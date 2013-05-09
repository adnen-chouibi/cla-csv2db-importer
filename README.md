Claromentis CSV to Database Importer
====================================
cla-csv2db-importer allows you to easily import CSV file data directly into your database.
## Usage ##

    $importer = new Importer("path_to_my_csv_file.csv");
    
#### Links ####
To specify links between the CSV header and database column name use <code>AddLink</code> method:

    $importer->AddLink('person_name', 'name');
    
#### Callbacks ####
To add callback function for each CSV record entity within a header use:

    $importer->AddCallback('personality_type', 'Person::PersonalityStringToID');

This way you can convert each CSV record into the required database field type. 

#### Validation ####
To add data validation use <code>AddValidation</code> method. This can be quite useful when we want to make sure that 
data in CSV does match the database field type.

    $importer->AddValidation('person_age', 25, Importer::INT, true);
