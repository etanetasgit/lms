<?php

class FBOAuth {

  private $db;
  private $session;
  private $appID;
  private $appSecret;
  private $graphVersion;

  public function __construct(&$DB, &$SESSION=null){
    GLOBAL $CONFIG;
    $this->db = $DB;
    $this->session = $SESSION;
    if(ConfigHelper::getConfig('facebook.oauth_app_id')){
      $this->appID = ConfigHelper::getConfig('facebook.oauth_app_id');
    } else {
      throw new Error("Facebook oauth_app_id is missing in config");
      error_log("Facebook oauth_app_id is missing in config");
    }
    if(ConfigHelper::getConfig('facebook.oauth_app_secret')){
      $this->appSecret = ConfigHelper::getConfig('facebook.oauth_app_secret');
    } else {
      throw new Error("Facebook oauth_app_secret is missing in config");
      error_log("Facebook oauth_app_secret is missing in config");
    }
    $this->graphVersion = "v5.0";
  }

  public function GenCallbackURL(){
    global $CONFIG;
    $fb = new Facebook\Facebook([
    'app_id' => $this->appID,
    'app_secret' => $this->appSecret,
    'default_graph_version' => $this->graphVersion,
    ]);

    $helper = $fb->getRedirectLoginHelper();

    $permissions = ['email'];

    $cbUri = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/?callback=facebook';
    $loginUrl = $helper->getLoginUrl($cbUri, $permissions);
    return $loginUrl;
  }

  public function Auth(){
    $authdata = null;

    $fb = new Facebook\Facebook([
    'app_id' => $this->appID,
    'app_secret' => $this->appSecret,
    'default_graph_version' => $this->graphVersion,
    ]);

    $helper = $fb->getRedirectLoginHelper();
    $_SESSION['FBRLH_state']=$_GET['state'];

    try {
        $accessToken = $helper->getAccessToken();
    } catch(Facebook\Exceptions\FacebookResponseException $e) {
        echo trans("Server error, code:")." 102321";
        error_log('Graph returned an error: ' . $e->getMessage());
        exit;
    } catch(Facebook\Exceptions\FacebookSDKException $e) {
        echo trans("Server error, code:")." 102322";
        error_log('Facebook SDK returned an error: ' . $e->getMessage());
        exit;
    }

    if (!isset($accessToken)) {
        if ($helper->getError()) {
            header('HTTP/1.0 500 Server Error');
            error_log("Facebook helper error 401 Unautorized");
            error_log("Error: " . $helper->getError() . "\n");
            error_log("Error Code: " . $helper->getErrorCode() . "\n");
            error_log("Error Reason: " . $helper->getErrorReason() . "\n");
            error_log("Error Description: " . $helper->getErrorDescription() . "\n");
        } else {
            header('HTTP/1.0 500 Server Error');
            error_log("Facebook helper error 400 Bad request");
        }
        echo trans("Server error, code:")." 102323";
        exit;
    }

    $oAuth2Client = $fb->getOAuth2Client();

    $tokenMetadata = $oAuth2Client->debugToken($accessToken);

    try {
        $token = $accessToken->getValue();
        $response = $fb->get('/me?fields=name,email', $token);
    } catch(Facebook\Exceptions\FacebookResponseException $e) {
        echo trans("Server error, code:")." 102324";
        error_log('Graph returned an error: ' . $e->getMessage());
        exit;
    } catch(Facebook\Exceptions\FacebookSDKException $e) {
        echo trans("Server error, code:")." 102325";
        error_log('Facebook SDK returned an error: ' . $e->getMessage());
        exit;
    }

    $fbuser = $response->getGraphUser();
    if(!$fbuser['email']){
      echo trans("Server error, code:")." 102326<br />";
      echo trans("Error while fetching user info from Facebook")."<br />";
      error_log("Received empty user email from Facebook, user: ",$fbuser["name"]);
      exit;
    }

    $users = $this->GetUsersByEmail($fbuser['email']);
    if(count($users) == 1){
      $user = $users[0];
    } elseif(count($users) == 0){
      if($this->session){
        error_log("Facebook auth failed, ".$fbuser['email']." not found in database");
        $this->session->error = trans("Email")." ".$fbuser['email']." ".trans("not found");
      }
    } else {
      foreach($users as $u){
        $name = strtolower($u['name'])." ".strtolower($u['lastname']);
        if(trim(strtolower($fbuser['name'])) == $name){
          $user = $u;
          break;
        } 
      }
      if(!$user){
        error_log("Facebook auth failed, more than one user with email ".$fbuser['email']." found, name mismatch");
        $this->session->error = trans("Email")." ".$fbuser['email']." ".trans("can't be authorized");
      }
    }
    if($user){
      if($user['validated'] != 't'){
        try{
          $this->GenAndSendValidationEmail($user['id'], $fbuser['email'], $user['contact_id']);
          echo trans("Email validation request send to")." ".$fbuser['email'];
          exit();
        } catch(Exception $e){
          echo trans("Server error, code:")." 102327";
          error_log($e);
          exit();
        }
      } else {
        $authdata['id'] = $user['id'];
        $authdata['passwd'] = $user['pin'];
      }
    }
    return $authdata;
  }

  public function ValidateEmail($token){
    if(!$token){
      echo trans("Server error, code:")." 102332<br />";
      echo trans("Token missing")." 102332<br />";
      exit();
    }
    $dbRow = $this->GetValidationQueueByToken($token);
    if(!$dbRow){
      echo trans("Server error, code:")." 102328";
      error_log("Token: $token not found");
      exit();
    }
    if($dbRow["expires_at"]<time()){
      echo trans("Server error, code:")." 102329 <br />";
      echo trans("Token expired")."<br />";
      error_log("Token ${$dbRow['token']}s expired");
      exit();
    }
    if($dbRow["used"] == "t"){
      echo trans("Server error, code:")." 102330 <br />";
      echo trans("Token has been used")."<br />";
      error_log("Token ${$dbRow['token']}s has been used");
      exit();
    }
    $this->db->BeginTrans();
    try{
      $this->SetTokenAsUsed($dbRow["id"]);
      $this->SetEmailAsValidated($dbRow["contact_id"]);
      $this->db->CommitTrans();
    } catch (Exception $e){
      $this->db->RollbackTrans();
      echo trans("Server error, code:")." 102331 <br />";
      error_log("Error while updating fbmailvalidationqueue or customercontacts");
      exit();
    }
  }

  private function GenToken($email, $id){
    $secret = $this->appSecret;
    $token = hash("sha256",microtime().$email.$secret.(string)$id);
    return $token;
  }

  private function GetUsersByEmail($email){
    $users = null;
    $sql = "select *, cc.validated, cc.id as contact_id from customercontacts as cc 
            inner join customers as c on c.id=cc.customerid
            where lower(cc.contact) = lower(?) and cc.type > 0 and c.deleted != 1 and c.status in ?";
    $users = $this->db->GetAll($sql, array($email, array(CSTATUS_CONNECTED,CSTATUS_WAITING)));
    return $users;
  }

  private function GenAndSendValidationEmail($userID, $userEmail, $emailID){
    global $LMS;
    $token = $this->GenToken($userEmail, $userID);
    $cbUri = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . "/?callback=fbmailvalidate&token=$token";
    $smtp_from = ConfigHelper::getConfig("facebook.smtp_from");
    if(!$smtp_from){
      $smpt_from = ConfigHelper::getConfig("mail.smtp_username");
    }
    $smtp_options = array();
    $subject = ConfigHelper::getConfig("facebook.validation_mail_subject");
    if(!$subject){
      $subject = trans("Email validation request");
    }
    $body = ConfigHelper::getConfig("facebook.validation_mail_body");
    if(!$body){
      $body = $cbUri;
    }

    $body = str_replace("%token", $token, $body);
    $body = str_replace("%url", $cbUri, $body);

    $headers = array();
    $headers['Subject'] = $subject;
    $headers["From"] = $smtp_from;
    $headers["X-LMS-Format"] = "html";

    $this->db->BeginTrans();
    try{
      $this->InsertValidationQueue($emailID, $token);
    } catch (Exception $e){
      $this->db->RollbackTrans();
      throw $e;
    }

    $sendResp = $LMS->SendMail($userEmail, $headers, $body, null, null, $smtp_options);
    if(gettype($sendResp) == "string"){
      $this->db->RollbackTrans();
      throw new Exception($sendResp);
    }
    $this->db->CommitTrans();
  }

  private function SetEmailAsValidated($contactID){
    if(!contactID){
      throw new Exception("Incorrect contact id: $contactID");
    }
    $r = $this->db->Execute("update customercontacts set validated = 't' where id = ?", array($contactID));
    if(!$r){
      throw new Exception("Failed to set customer contact as validated");
    }
  }

  private function SetTokenAsUsed($tokenID){
    if(!tokenID){
      throw new Exception("Incorrect token id: $tokenID");
    }
    $r = $this->db->Execute("update fbmailvalidationqueue set used='t', updated_at=? where id = ?", array(time(), $tokenID));
    if(!$r){
      throw new Exception("Failed to set token as used");
    }
  }

  private function GetValidationQueueByToken($token){
    $res = $this->db->GetRow(
      "select * from fbmailvalidationqueue where token = ?",
      array($token, $now)
    );
    return $res;
  }

  private function InsertValidationQueue($emailID, $token){
    $now = time(); 
    $expires = $now+3600;
    $r = $this->db->Execute("
    INSERT into fbmailvalidationqueue (contact_id, token, used, expires_at, created_at, updated_at)
    VALUES (?,?,'f',?,?,?)
    ", array($emailID, $token, $expires, $now, $now));
    if(!$r){
      throw new Exception("Failed to insert into fbmailvalidationqueue");
    }
  }
}