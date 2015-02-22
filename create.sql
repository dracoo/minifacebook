create table "school" (
id serial PRIMARY KEY,
"name" varchar(255) not null,
address varchar(255) not null,
city varchar(255) not null
);

create table "user" (
id serial PRIMARY KEY,
firstname varchar(255) NOT NULL,
lastname varchar(255) NOT NULL,
email varchar(255) NOT NULL,
password char(32) NOT NULL,
birthdate date,
birthplace varchar(255),
gender smallint,
domicile_city varchar(255),
domicile_province varchar(255),
domicile_state varchar(255),
vip smallint NOT NULL,
fav_post_id int,
fav_post_user_id int
);

create table "user_school" (
id serial PRIMARY KEY,
school_id int not null,
user_id int not null,
year_begin int not null,
year_end int,
FOREIGN KEY (school_id) REFERENCES "school" (id),
FOREIGN KEY (user_id) REFERENCES "user" (id)
);

create table "post" (
id int NOT NULL,
user_id int NOT NULL,
main_text varchar(255) NOT NULL,
second_text varchar(50),
latitude float,
longitude float,
"type" smallint NOT NULL,
createdat timestamp not null,
PRIMARY KEY (id, user_id),
FOREIGN KEY (user_id) REFERENCES "user" (id)
);

ALTER TABLE "user" ADD CONSTRAINT favorite_place_key FOREIGN KEY (fav_post_id, fav_post_user_id) REFERENCES "post" (id, user_id);

create table "comment" (
id serial PRIMARY KEY,
responder_id int NOT NULL,
post_id int NOT NULL,
post_user_id int NOT NULL,
content VARCHAR(255) not null,
createdat timestamp not null,
FOREIGN KEY (post_id, post_user_id) REFERENCES "post" (id, user_id)
);

create table "tag" (
id serial PRIMARY KEY,
tagged_id int NOT NULL,
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
FOREIGN KEY (sender_id) REFERENCES "user" (id),
FOREIGN KEY (receiver_id) REFERENCES "user" (id)
);

INSERT INTO "user" (firstname, lastname, email, password, vip) VALUES ('Filippo', 'Grecchi', 'filippo@filippogrecchi.it', 'e32ae4e0d9158c00684ec73ce7803ab1', 0);
INSERT INTO "user" (firstname, lastname, email, password, vip) VALUES ('Mario', 'Rossi', 'mario.rossi@gmail.com', 'e32ae4e0d9158c00684ec73ce7803ab1', 0);
INSERT INTO "user" (firstname, lastname, email, password, vip) VALUES ('Valeria', 'Bianchi', 'valeria.bianchi@gmail.com', 'e32ae4e0d9158c00684ec73ce7803ab1', 0);
INSERT INTO "user" (firstname, lastname, email, password, vip) VALUES ('Francesca', 'Verdi', 'francesca.verdi@gmail.com', 'e32ae4e0d9158c00684ec73ce7803ab1', 0);

INSERT INTO school ("name", address, city) VALUES ('Università degli Studi di Milano', 'via Festa del Perdono', 'Milano');
INSERT INTO school ("name", address, city) VALUES ('ITIS Molinari', 'via Crescenzago', 'Milano');
INSERT INTO school ("name", address, city) VALUES ('Università degli Studi di Roma "La Sapienza")', 'Piazzale Aldo Moro', 'Milano');