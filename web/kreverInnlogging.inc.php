<?php
/*
Kontrollerer at bruker er logget inn før siden vises
Brukes på alle beskyttede sider i administrasjonspanelet
Hvis ingen gyldig session finnes sendes bruker tilbake til innlogging
*/

session_start();

/*
Sjekker om bruker har gyldig innlogget-session
Uautoriserte brukere sendes tilbake til loggInn.php
*/
if (!isset($_SESSION["innlogget"]) || $_SESSION["innlogget"] !== true) {
    header("Location: loggInn.php");
    exit();
}
