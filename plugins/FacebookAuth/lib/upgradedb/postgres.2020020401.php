<?php

$this->BeginTrans();

$exists = $this->GetOne("
  SELECT EXISTS (SELECT 1 
  FROM information_schema.tables 
  WHERE table_name='contactvalidated')
");


if($exists != "t"){
  $this->Execute("CREATE SEQUENCE contactvalidated_id_seq");
  $this->Execute("
    CREATE TABLE public.contactvalidated (
      id integer DEFAULT nextval('contactvalidated_id_seq'::text) NOT NULL,
      contact varchar(255) NOT NULL,
      customer_id integer  NOT NULL,
      validated boolean default false,
      PRIMARY KEY (id),
      UNIQUE (contact)
    );
  ");
} 

$this->Execute("UPDATE dbinfo SET keyvalue = '2020020401' WHERE keytype='dbversion_FacebookAuth'");

$this->CommitTrans();