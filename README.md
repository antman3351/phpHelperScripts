# PHP Helper Scripts
Various PHP scripts


## findIncompatibleChildClassMethods.php
Looks for issues with methods of child classes. I wrote the script because when migrating a project from PHP 5.6 to PHP 7.3 I was getting warnings "Declaration of xChildClass:method($a) should be compatible with xParentClass::method($a, $b) in ...."
( as of PHP 7.0.x "Signature mismatch during inheritance	E_WARNING" )

### How to use:
Place in the folder where you want to scan and execute the script.
On the last few lines you can change the debug level (0-4) `debug::$level = 1;` for more or less info and the folder/file extention
`$test = new FindIncompatibleChildClassMethods( '../', '\.php' );` (note the extention is a regex).
