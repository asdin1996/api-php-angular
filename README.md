# api-php-angular
It's api php, I developed it to integrate Angular and PHP.

PHP Project

This project aims to serve as an API for Angular, connecting to the database, performing logic, etc.

Ensuring the quality of the API's operation

Avoiding dependency issues of 3GB/project

Regaining control of the entire lost server side (server logs for requests, for example)

It is very basic; the project consists of:

.htaccess
For friendly URLs

index.php
Initial script that receives the requests

__options.json
Configuration file for the script execution (DB, SMTP, etc.)

ApiController.php
Main class that manages the different DB queries

Database.php
Main class that executes the actual DB calls

API Operation
Example URLs:
https://cultural.agenciaekiba.com/api/add/ejemplos
https://cultural.agenciaekiba.com/api/list/ejemplos

Endpoints:

/api/list/ejemplos

/api/add/ejemplos

/api/edit/ejemplos

/api/delete/ejemplos

REQUEST FORMAT FOR LIST

Method: GET

Parameters:

action list

table xxxxxxx

params Object with different values:

filters AND filters to apply, array

order order to apply, array

pagesize page size

page page to display

REQUEST FORMAT FOR SAVE

Method: POST

Parameters:

action add/edit/delete

table xxxx

data Array (one element per record):

table

data

Although table is repeated twice, the goal is to allow sub-records to be included in the same request to save multiple related records in bulk.

OPTIONS PER TABLE

table Name of the table

api_call Name used in the API URL

entity Name of the class resolving the entity (default: Entity)

name_field Name of the table field to show
List of fields separated by commas for concat, wrapped in "" for literals
Example: "[",codigo,"] ",nombre
Result: [codigo] nombre

entity_name_one Singular name for the entity

entity_name_multiple Plural name for the entity

list_fields List of fields separated by commas
Related fields included here are automatically converted to _value (a virtual field showing human-readable value instead of ID)

filter_fields List of fields separated by commas
If NULL, uses list fields
If a _value field is given, it converts automatically to _id

modal_list_fields Fields shown in modal selectors (if null, uses list fields)
[To be implemented]

modal_list_filters Fields to filter modals (if null, uses modal_list_fields)
[To be implemented]

export_fields Fields for export separated by commas
If NULL, exports all

default_order Default ordering in lists (if none, defaults to id DESC to show newest first)

include_base_fields Indicates if entity has internal system fields (deleted, user_add_id, datetime_add, etc.)

actions Whether the list allows actions (permissions aside—this enables/disables globally)

allow_crud_add Whether the list allows adding (permissions aside)

allow_crud_edit Whether the list allows editing (permissions aside)

allow_crud_view Whether the list allows viewing (permissions aside)

allow_crud_delete Whether the list allows deleting (permissions aside)

permissions [To be implemented]

related_config List of relationships for the current table, separated by commas
Each relation defined as:
target_table#field_in_target_table#field_in_parent_table
Example: ejemplos_lineas@ejemplo_id#id means that in the ejemplos_lineas table there is a field [ejemplo_id] which automatically takes the value of [id] in the ejemplos table.
This allows related records for non-existent parents to work correctly by auto-filling this field with the parent's ID when saving.

list_buttons [To be implemented]

edit_buttons [To be implemented]

OPTIONS PER FIELD

table_id Related table ID

field_order Field order (affects default order in forms)

field Field name in DB

type Field type in DB

label Label shown for the field

default_value Default value for new records (input pre-filled when adding)
Special values:

today → current date when creating record

now → current datetime when creating record

placeholder Placeholder text when input is empty

tipo_icono Icon type (currently only Google icons, in case different icon families are added)

label_icon Icon identifier string (for Google icons, the name used in the <span>)

label_text If the icon should be a character (e.g. %, €, cm...)

editable Whether the field is editable

hidden Whether the field is hidden

required Whether the field is mandatory

allow_null [To be implemented]

input_container_class Optional CSS class for styling the input container

enum_options JSON with enum values {dbValue:displayValue,...}, only for enum fields
Example: {"a":"Option A","b":"Option B","c":"Option C"}

related_options Name of related table (only for related fields)

decimal_precision Decimal precision (only for decimal type)

max_size Maximum input size (for strings)