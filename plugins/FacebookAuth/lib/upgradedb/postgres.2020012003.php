<?php

$this->BeginTrans();

$options = array(
  array(
    "name"=>"oauth_app_id",
    "description"=>"Facebook app id"
  ),
  array(
    "name"=>"oauth_app_secret",
    "description"=>"Facebook app secret"
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
  $exists = $this->GetOne("SELECT id FROM uiconfig where section = 'facebook' and disabled = 0 and var=?", array($option['name']));
  if(!$exists){
    $this->Execute("INSERT into uiconfig (section, var, value, description, disabled) values (?,?,?,?,?)",array(
      "facebook",
      $option['name'],
      "",
      $option['description'],
      0
    ));
  }
}

$this->Execute("UPDATE dbinfo SET keyvalue = '2020012003' WHERE keytype='dbversion_FacebookAuth'");

$this->CommitTrans();