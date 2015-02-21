create table "user" (
id serial PRIMARY KEY,
firstname varchar(255) NOT NULL,
lastname varchar(255) NOT NULL,
email varchar(255) NOT NULL,
password char(32) NOT NULL,
birthdate date,
birthplace varchar(255),
gender boolean,
domicile_city varchar(255),
domicile_province varchar(255),
domicile_state varchar(255),
vip boolean NOT NULL DEFAULT FALSE,
favorite_place_post_id int,
favorite_place_post_user_id int
);

CREATE TYPE post_type AS ENUM ('1','2','3');
create table "post" (
id int NOT NULL,
user_id int NOT NULL,
main_text varchar(255) NOT NULL,
second_text varchar(50),
latitude float,
longitude float,
"type" post_type NOT NULL,
createdat timestamp not null,
PRIMARY KEY (id, user_id),
FOREIGN KEY (user_id) REFERENCES "user" (id)
);

ALTER TABLE "user" ADD FOREIGN KEY (favorite_place_post_id, favorite_place_post_user_id) REFERENCES "post" (id, user_id);

create table "comment" (
id serial PRIMARY KEY,
user_id int NOT NULL,
post_id int NOT NULL,
post_user_id int NOT NULL,
content VARCHAR(255) not null,
FOREIGN KEY (post_id, post_user_id) REFERENCES "post" (id, user_id)
);

create table "tag" (
id serial PRIMARY KEY,
user_id int NOT NULL,
post_id int NOT NULL,
post_user_id int NOT NULL,
FOREIGN KEY (post_id, post_user_id) REFERENCES "post" (id, user_id)
);

create table "friend" (
sender_id int not null,
receiver_id int not null,
sentat timestamp  not null,
acceptedat timestamp,
PRIMARY KEY (sender_id, receiver_id),
FOREIGN KEY (sender_id ) REFERENCES "user" (id),
FOREIGN KEY (receiver_id ) REFERENCES "user" (id)
);