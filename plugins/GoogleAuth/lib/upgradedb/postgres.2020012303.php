<?php

$this->BeginTrans();

$options = array(
  array(
    "name"=>"credential_json_path",
    "description"=>"Google json file with credentials (from google console)"
  ),
  array(
    "name"=>"smtp_from",
    "description"=>"Email address from which email will be send validation mail, mail.smtp_username will be used if empty"
  ),
  array(
    "name"=>"validation_mail_subject",
    "description"=>"Validation email subject, default will be used if empty"
  ),
  array(
    "name"=>"validation_mail_body",
    "description"=>"Validation email body, default will be used if empty, %token, %url variables can be used"
  ),

);

foreach($options as $option){
  $exists = $this->GetOne("SELECT id FROM uiconfig where section = 'google' and disabled = 0 and var=?", array($option['name']));
  if(!$exists){
    $this->Execute("INSERT into uiconfig (section, var, value, description, disabled) values (?,?,?,?,?)",array(
      "google",
      $option['name'],
      "",
      $option['description'],
      0
    ));
  }
}

$this->Execute("UPDATE dbinfo SET keyvalue = '2020012303' WHERE keytype='dbversion_GoogleAuth'");

$this->CommitTrans();