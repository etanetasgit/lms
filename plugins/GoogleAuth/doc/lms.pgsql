BEGIN;

CREATE SEQUENCE googlemailvalidationqueue_id_seq;
CREATE TABLE public.googlemailvalidationqueue (
	id integer DEFAULT nextval('googlemailvalidationqueue_id_seq'::text) NOT NULL,
	contact_id integer NOT NULL,
	"token" varchar(128) NOT NULL,
	used boolean NOT NULL DEFAULT false,
	expires_at integer NOT NULL,
	created_at integer NULL,
	updated_at integer NULL,
  PRIMARY KEY (id),
	UNIQUE (token)
);

INSERT INTO dbinfo (keytype, keyvalue) VALUES ('dbversion_GoogleAuth', '2020012301');

COMMIT;