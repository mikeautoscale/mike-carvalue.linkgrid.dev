# VinAudit: CarValue Trial Project

# Objective

Create a simple internal search interface for estimating the average market value for a year / make / model.

# UI Requirements

Create a clean simple web interface (aligned and spaced properly; but doesn't need to look fancy) with:

* Search page:  
  * Input \#1 (required): Year \+ Make \+ Model (example: 2015 Toyota Camry)  
  * Input \#2 (optional): Mileage (example: 150,000 miles)  
* Results page:  
  * Display estimated market price computed based on listings for similar year \+ make \+ model vehicles.  
    * Example: $13,800 (rounded to the nearest hundred)  
  * Display a list of up to 100 sample listings that were used to compute the market value:  
    * Example:  
      

| Vehicle | Price | Mileage | Location |
| :---- | :---- | :---- | :---- |
| 2015 Toyota Camry CE | $12,500 | 131,400 miles | Seattle, WA |
| 2015 Toyota Camry CE | $11,700 | 173,389 miles | Dallas, TX |
| 2015 Toyota Camry LE | $17,100 | 141,839 miles | Newark, NJ |
| ... |  |  |  |

# Technical Requirements

1\. The market value estimate is derived based on the average of similar cars in the Full Market Data file (supplied below)

2\. The website is powered by a database and web server both running on the server to be supplied.

3\. The PHP code is clean and object-oriented with standard and open-source libraries.

# Technical Challenges

1. There is typically a negative correlation between price and mileage \-- as mileage goes up, the value of a car goes down:  
   ![][image1]  
   How do we account for the "mileage" input when estimating the market value for a given year \+ make \+ model?

2. What other factors can we incorporate to make a more accurate market value estimate for a given year \+ make \+ model?

# Inputs

The only external data needed for this project is the Full Market Data File linked below:

* **Full Market Data File:** https://linkgrid.com/downloads/carvalue\_project/inventory-listing-2022-08-17.txt  
* **Preview File:** https://linkgrid.com/downloads/carvalue\_project/inventory-listing-2022-08-17\_first1000.txt

# Deliverables

For this project to be considered completed, we expect the following deliverables:

* A link to web interface running on the virtual server supplied for testing  
* A design document describing:  
  * The database schema  
  * The API layer retrieving the market value and comparable listings  
  * The solution describing how the input data gets translated into the database and into output displayed in the web server  
* Clean, well-organized, object-oriented source code for the project  
* Create a list of verified integration tests, including test cases.

