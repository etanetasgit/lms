<?php

class GoogleOAuth {

  private $db;
  private $session;
  private $appID;
  private $appSecret;
  private $credentialsJson;

  public function __construct(&$DB, &$SESSION=null){
    GLOBAL $CONFIG;
    $this->db = $DB;
    $this->session = $SESSION;
    $this->appSecret =  "06Tyctd7GPuwSVw1";
    if(ConfigHelper::getConfig('google.credential_json_path')){
      $this->credentialsJson = ConfigHelper::getConfig('google.credential_json_path');
    } else {
      throw new Error("Google credential_json_path is missing in config");
      error_log("Google credential_json_path is missing in config");
    }
    if(ConfigHelper::getConfig('google.secret')){
      $this->appSecret = ConfigHelper::getConfig('google.secret');
    } else {
      error_log("secret is missing in google config");
    }

// $client->setClientId($OAUTH2_CLIENT_ID);
// $client->setClientSecret($OAUTH2_CLIENT_SECRET);
  }

  public function GenCallbackURL(){
    $client = $this->CreateClient();
    $loginUrl = $client->createAuthUrl();
    return $loginUrl;
  }

  public function Auth(){
    $authdata = null;
    $client = $this->CreateClient();
    $resp = $client->authenticate($_GET['code']);
    if($resp["error"]){
      throw new Exception($resp["error"]." ".$resp["error_description"]);
    }
    $token = $client->getAccessToken();
    $resp = $client->setAccessToken($token);
    $service = new Google_Service_Oauth2($client);
    $userinfo = $service->userinfo->get();

    if(!$userinfo['email']){
      echo trans("Server error, code:")." 103326<br />";
      echo trans("Error while fetching user info from google")."<br />";
      error_log("Received empty user email from google, user: ",$userinfo["name"]);
      exit;
    }

    $users = $this->GetUsersByEmail($userinfo['email']);
    if(count($users) == 1){
      $user = $users[0];
    } elseif(count($users) == 0){
      if($this->session){
        error_log("Google auth failed, ".$userinfo['email']." not found in database");
        $this->session->error = trans("Email")." ".$userinfo['email']." ".trans("not found");
      }
    } else {
      foreach($users as $u){
        $name = strtolower($u['name'])." ".strtolower($u['lastname']);
        if(trim(strtolower($userinfo['name'])) == $name){
          $user = $u;
          break;
        }
      }
      if(!$user){
        error_log("Google auth failed, more than one user with email ".$userinfo['email']." found, name mismatch");
        $this->session->error = trans("Email")." ".$userinfo['email']." ".trans("can't be authorized");
      }
    }
    if($user){
      if($user['validated'] != 't'){
        try{
          $this->GenAndSendValidationEmail($user['id'], $userinfo['email'], $user['contact_id']);
          echo trans("Email validation request send to")." ".$userinfo['email'];
          exit();
        } catch(Exception $e){
          echo trans("Server error, code:")." 103327";
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
      echo trans("Server error, code:")." 103332<br />";
      echo trans("Token missing")." 103332<br />";
      exit();
    }
    $dbRow = $this->GetValidationQueueByToken($token);
    if(!$dbRow){
      echo trans("Server error, code:")." 103328";
      error_log("Token: $token not found");
      exit();
    }
    if($dbRow["expires_at"]<time()){
      echo trans("Server error, code:")." 103329 <br />";
      echo trans("Token expired")."<br />";
      error_log("Token ${$dbRow['token']}s expired");
      exit();
    }
    if($dbRow["used"] == "t"){
      echo trans("Server error, code:")." 103330 <br />";
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
      echo trans("Server error, code:")." 103331 <br />";
      error_log("Error while updating googlemailvalidationqueue or customercontacts");
      exit();
    }
  }

  private function CreateClient(){
    $client = new Google_Client();
    $client->setAuthConfig($this->credentialsJson);
    $cbUri = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . '/?callback=google';
    $client->setRedirectUri($cbUri);
    $client->addScope("email");
    $client->addScope("profile");
    return $client;
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
    $cbUri = $_SERVER['REQUEST_SCHEME'] . '://' . $_SERVER['HTTP_HOST'] . "/?callback=googlemailvalidate&token=$token";
    $smtp_from = ConfigHelper::getConfig("google.smtp_from");
    if(!$smtp_from){
      $smpt_from = ConfigHelper::getConfig("mail.smtp_username");
    }
    $smtp_options = array();
    $subject = ConfigHelper::getConfig("google.validation_mail_subject");
    if(!$subject){
      $subject = trans("Email validation request");
    }
    $body = ConfigHelper::getConfig("google.validation_mail_body");
    if(!$body){
      $body = $cbUri;
    }

    $headers = array();
    $headers['Subject'] = $subject;
    $headers["From"] = $smtp_from;

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
    $r = $this->db->Execute("update googlemailvalidationqueue set used='t', updated_at=? where id = ?", array(time(), $tokenID));
    if(!$r){
      throw new Exception("Failed to set token as used");
    }
  }

  private function GetValidationQueueByToken($token){
    $res = $this->db->GetRow(
      "select * from googlemailvalidationqueue where token = ?",
      array($token, $now)
    );
    return $res;
  }

  private function InsertValidationQueue($emailID, $token){
    $now = time(); 
    $expires = $now+3600;
    $r = $this->db->Execute("
    INSERT into googlemailvalidationqueue (contact_id, token, used, expires_at, created_at, updated_at)
    VALUES (?,?,'f',?,?,?)
    ", array($emailID, $token, $expires, $now, $now));
    if(!$r){
      throw new Exception("Failed to insert into googlemailvalidationqueue");
    }
  }
}