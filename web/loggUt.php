<?php
/*
Logger ut bruker fra administrasjonspanelet
Eksisterende session-data fjernes før bruker sendes tilbake til innlogging
*/

session_start();

// Tømmer alle session-variabler
$_SESSION = [];

// Avslutter aktiv session
session_destroy();

// Sender bruker tilbake til innloggingssiden
header("Location: loggInn.php");
exit();
