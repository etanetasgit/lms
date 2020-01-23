<?php

$this->BeginTrans();
$colExists = $this->GetOne("
  SELECT EXISTS (SELECT 1 
  FROM information_schema.columns 
  WHERE table_name='customercontacts' AND column_name='validated')
");

if($colExists != 't'){
  $this->Execute("ALTER TABLE customercontacts ADD COLUMN validated boolean DEFAULT 'f'");
}

$this->Execute("UPDATE dbinfo SET keyvalue = '2020012002' WHERE keytype='dbversion_FacebookAuth'");

$this->CommitTrans();