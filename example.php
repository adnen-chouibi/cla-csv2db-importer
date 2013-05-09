<?php
//Include Claromentis core files here

$importer = new Importer("path_to_my_csv_file.csv");

//Specify links between CSV and Database
//usage: AddLink(string $csv_header_name, string $database_header_name)
$importer->AddLink('person_name', 'name');
$importer->AddLink('person_age', 'age');
$importer->AddLink('personality_type', 'type_id');
$importer->AddLink('email', 'email');

//Add Callback
//adds a callback function to each CSV entity specified by header
//usage: AddCallback(string $csv_header_name, string $function_name)
$importer->AddCallback('personality_type', 'Person::PersonalityStringToInteger()');

//Perform data validation, this is useful when we want to make sure that data in CSV match database type
//usage: AddValidation(string $csv_header_name, int $db_default_value=null, int $type=self::STRING, bool $is_mandatory=false)
$importer->AddValidation('person_age', 25, Importer::INT, true);
$importer->AddValidation('personality_type', 0, Importer::STRING, false);