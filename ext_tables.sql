CREATE TABLE tx_aigelb_domain_model_agent (
	tx_aigelb_agentid VARCHAR(255) DEFAULT '' NOT NULL
);

CREATE TABLE tx_aigelb_domain_model_questions (
	question VARCHAR(255) DEFAULT '' NOT NULL
);

CREATE TABLE pages (
	tx_aigelb_promptrequirement TEXT,
	tx_aigelb_knowledgebase VARCHAR(255) DEFAULT '' NOT NULL,
	tx_aigelb_language VARCHAR(2) DEFAULT '' NOT NULL,
	tx_aigelb_lastupdated INT(11) DEFAULT '0' NOT NULL,
	tx_aigelb_knowledgeid VARCHAR(255) DEFAULT '' NOT NULL,
	tx_aigelb_indexpage TINYINT(1) DEFAULT '0' NOT NULL,
);
