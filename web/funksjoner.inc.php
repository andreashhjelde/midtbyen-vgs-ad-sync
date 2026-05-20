<?php

/*
Felles hjelpefunksjoner for Midtbyen VGS sitt AD-sync statuspanel

Webserveren henter status fra sync-servere via SSH med egen websync-bruker
websync har kun sudo-rettigheter til forhåndsdefinerte AD-sync-kommandoer
*/

/*
Liste over sync-servere og SSH-tilkoblingsdetaljer

Dersom flere sync-servere legges til kan de legges inn i $syncServere
For eksempel: "linux03" => "192.168.56.13"
*/
$syncServere = [
    "linux01" => "192.168.56.11",
    "linux02" => "192.168.56.12",
];

$sshKey = "/var/www/.ssh/id_ed25519";
$sshUser = "websync";


// Kjører shell-kommando og returnerer både output og returkode
function hentKommandoOutput($kommando)
{
    $output = [];
    $returkode = 0;

    exec($kommando . " 2>&1", $output, $returkode);

    return [
        "output" => $output,
        "returkode" => $returkode
    ];
}


// Henter listen over definerte sync-servere
function hentSyncServere()
{
    global $syncServere;

    return $syncServere;
}


// Sjekker at valgt server finnes i serverlisten
function erGyldigSyncServer($serverNavn)
{
    global $syncServere;

    return isset($syncServere[$serverNavn]);
}


// Henter IP-adresse for valgt sync-server
function hentSyncServerIp($serverNavn)
{
    global $syncServere;

    return $syncServere[$serverNavn] ?? null;
}


// Bygger SSH-kommando mot valgt sync-server
function byggSshKommando($serverNavn, $remoteKommando)
{
    global $sshKey, $sshUser;

    $serverIp = hentSyncServerIp($serverNavn);

    if ($serverIp === null) {
        return false;
    }

    return "ssh -i " . escapeshellarg($sshKey)
        . " -o BatchMode=yes"
        . " -o StrictHostKeyChecking=accept-new"
        . " " . escapeshellarg($sshUser . "@" . $serverIp)
        . " " . escapeshellarg($remoteKommando);
}


// Henter status for systemd-service eller timer på valgt sync-server
function hentServiceStatus($serverNavn, $serviceNavn)
{
    $remoteKommando = "sudo systemctl is-active " . escapeshellarg($serviceNavn);
    $sshKommando = byggSshKommando($serverNavn, $remoteKommando);

    if ($sshKommando === false) {
        return "unknown";
    }

    $resultat = hentKommandoOutput($sshKommando);

    if ($resultat["returkode"] === 0) {
        return "active";
    }

    return trim(implode("\n", $resultat["output"])) ?: "unknown";
}


// Henter siste logglinjer fra AD-sync-loggen på valgt sync-server
function hentSisteLoggLinjer($serverNavn, $filsti, $antallLinjer = 40)
{
    $remoteKommando = "sudo tail -n " . (int)$antallLinjer . " " . escapeshellarg($filsti);
    $sshKommando = byggSshKommando($serverNavn, $remoteKommando);

    if ($sshKommando === false) {
        return ["Ugyldig sync-server valgt."];
    }

    $resultat = hentKommandoOutput($sshKommando);

    if ($resultat["returkode"] !== 0) {
        return ["Kunne ikke hente logg fra sync-server."];
    }

    return $resultat["output"];
}


// Henter tidspunkt for siste endring på loggfilen på valgt sync-server
function hentSisteSyncTid($serverNavn, $filsti)
{
    $remoteKommando = "stat -c %Y " . escapeshellarg($filsti);
    $sshKommando = byggSshKommando($serverNavn, $remoteKommando);

    if ($sshKommando === false) {
        return "Ukjent";
    }

    $resultat = hentKommandoOutput($sshKommando);

    if ($resultat["returkode"] !== 0 || empty($resultat["output"][0])) {
        return "Ukjent";
    }

    return date("d.m.Y H:i:s", (int)$resultat["output"][0]);
}


// Starter AD-sync manuelt på valgt sync-server via systemd
function kjorManuellSync($serverNavn)
{
    $remoteKommando = "sudo systemctl start ad-sync.service";
    $sshKommando = byggSshKommando($serverNavn, $remoteKommando);

    if ($sshKommando === false) {
        return false;
    }

    $resultat = hentKommandoOutput($sshKommando);

    return $resultat["returkode"] === 0;
}
