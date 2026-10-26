TOKA AUTHENTICATION (HTML + PHP)

Files:
- index.php       Login page
- register.php    Registration page
- auth.php        Login/register form processing
- welcome.php     Simple post-login page for testing
- logout.php      Ends the session
- auth.css        Shared styling
- toka_bird.png   Logo mark from the prototype reference
- users.json      Temporary local user storage (not MySQL yet)

RUNNING WITH XAMPP
1. Copy the entire Toka_Auth folder into:
   C:\xampp\htdocs\
2. Start Apache in XAMPP.
3. Open:
   http://localhost/Toka_Auth/

IMPORTANT:
The current version intentionally does not use MySQL.
It stores demo users in users.json so the login/register flow can be tested first.
When the front end is finalized, users.json can be replaced with MySQL + PDO.
