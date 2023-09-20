## Passenger PHP Code Task
### Initial Setup
Clone onto local system:

`git clone git@github.com:cgoosey1/passenger.git passenger.local`

Install dependencies with composer

`composer install`

Create a local database in MySQL

Copy `.env.example` file as `.env`, add local database connection details.

Run migrations

`php artisan migrate`

To quickly create a webserver to test API functionality use the command

`php artisan serve`

### Importing Postcodes
The postcode importer uses Ordnance Survey Data Hub, if you have an API code you should add it to the `.env`
file with parameter `OS_DATAHUB_API_KEY`.

You can run the postcode importer using `php artisan postcode:import`.

This coding tasks assumes you don't have an API key, so to make it simpler I have included a copy of the most recent
postcode zip file (as of September 2023), to utilise this use the --use-previous parameter 
(`php artisan postcode:import --use-previous`).

This will create a job for each postcode CSV file, these can be ran using the `php artisan queue:work` 
command.

One thing to note here is I did notice on my local system, after I had ran several jobs the command crashes due to
available memory. In production situations you often use a service like supervisor that would automatically restart
the jobs when they fail, since this is just a coding challenge I have not attempted to fix this issue.

### Notes around Importing Postcodes
Important files to assess: 
 - app/Console/Commands/PostcodeImport.php
 - app/Jobs/ProcessPostcodeCSV.php

I was a little concerned about handling a zip file, I am attempting to only extract files I need from this zip, in
the hopes that avoids a zip bomb scenario, I would also probably have this system in isolation from the rest of the 
application, just sending the individual postcode csv files to s3.

I also decided to use Jobs to handle the load of processing 1.8 odd million postcodes so we can keep that processing 
away from the main application.

I feel I might have over-engineered the postcode importer to handle inserts and updates, but oh well. Also I made
the choice on purpose not to convert the postcodes to latitude/longitude during the import as I felt it would be 
easier to handle the searching using Easting/Northing.

### Search by Text
You can call this route using `GET /api/postcode/search/text`, it expects the parameter `text` to be passed in.

There is no authentication/authorisation in this application, I didn't think it would add anything to the test and
didn't want to waste time on it.

Assuming you are using a web server created by `php artisan serve` you can make the following curl request to view data.
```
curl --location 'http://127.0.0.1:8000/api/postcode/search/text?text=mk17%209db'
```

### Notes about Search by Text
Important files to assess:
- app/Http/Controllers/PostcodeController.php

Wasn't really sure the best way to go about this, my first approach involved showing results that started with the
search term first, but ultimately decided to keep this really simple rather than lose performance trying to make
the search more relevant. Mixed feelings if this was the right approach.

I used Form Request Validation to handle the request data (visible in app/Http/Requests/SearchByTextRequest.php).

Also included Laravels pagination on this as short search terms will cause a lot of results.
### Search by Location
You can call this route using `GET /api/postcode/search/location`, it expects latitude and longitude to be passed in.

Again there is no auth on this route.

Assuming you are using a web server created by `php artisan serve` you can make the following curl request to view data.

```
curl --location --request GET 'http://127.0.0.1:8000/api/postcode/search/location?latitude=51.9558&longitude=-0.714016' \
```
Search radius is 0.5km, I know in the UK I should use miles, but I decided to make it easier on myself.
### Notes on Search by Location
Important files to assess:
- app/Http/Controllers/PostcodeController.php

I did a lot of heavy lifting with PHPCoord, I also use Form Request Validation to handle the request data 
(visible in app/Http/Requests/SearchByLocationRequest.php).

I would normally split up my logic a bit nicer, i.e. have PostcodeController@searchByLocation as it is, but have all 
other associated methods in a Service. Ultimately I decided just to keep it all in one class for you to review.

The associated methods are all designed to be simple with the thought of Unit
Testing, though I think in a few spots I could have probably made simpler methods more in keeping with Unit Testing.

### General Notes
I have focused on the specific requirements of the test and left a lot out, i.e. Authorisation, Testing, Logging etc.

Excited to hear feedback, let me know if you have any questions!
