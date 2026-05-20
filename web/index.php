<?php
/*
Midtbyen VGS - Statuspanel for AD-sync

Viser:
- status for systemd service og timer per sync-server
- siste loggaktivitet per sync-server
- siste logglinjer per sync-server
- mulighet for manuell synkronisering per sync-server
*/

require_once "kreverInnlogging.inc.php";
require_once "funksjoner.inc.php";

// Faste stier og systemd-enheter brukt av statuspanelet
$loggFil = "/var/log/ad-sync.log";
$serviceNavn = "ad-sync.service";
$timerNavn = "ad-sync.timer";

// Henter definerte sync-servere fra funksjoner.inc.php
$syncServere = hentSyncServere();

// Velger første server som standard dersom ingen server er valgt
$valgtServer = $_GET["server"] ?? array_key_first($syncServere);

// Stopper ugyldige servervalg før de brukes i SSH-kommandoer
if (!erGyldigSyncServer($valgtServer)) {
    $valgtServer = array_key_first($syncServere);
}

$syncStartet = false;

// Manuell sync startes kun ved POST-request på valgt server
if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["start_sync"])) {
    $valgtServer = $_POST["server"] ?? $valgtServer;

    if (!erGyldigSyncServer($valgtServer)) {
        $valgtServer = array_key_first($syncServere);
    }

    $syncStartet = kjorManuellSync($valgtServer);
}

// Henter statusdata som vises i dashboardet
$serviceStatus = hentServiceStatus($valgtServer, $serviceNavn);
$timerStatus = hentServiceStatus($valgtServer, $timerNavn);
$sisteSyncTid = hentSisteSyncTid($valgtServer, $loggFil);
$loggLinjer = hentSisteLoggLinjer($valgtServer, $loggFil, 40);

$serviceStatusVisning = $serviceStatus;
$serviceForklaring = "";

if ($serviceStatus === "inactive") {
    $serviceStatusVisning = "klar";
    $serviceForklaring = "Service kjører kun under synkronisering.";
    $serviceCssClass = "active";
}

?>

<!doctype html>
<html lang="no">
<head>
    <meta charset="utf-8">
    <title>Midtbyen VGS - AD-sync status</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <?php require_once "meny.inc.php"; ?>

    <main class="container">
        <section class="page-header">
            <h1>AD-sync status</h1>
            <p>Oversikt over synkronisering mellom Active Directory og Linux-servere.</p>
        </section>

        <section class="card">
            <h2>Velg sync-server</h2>

            <form method="get">
                <label for="server">Server</label>
                <select id="server" name="server" onchange="this.form.submit()">
                    <?php foreach ($syncServere as $navn => $ip): ?>
                        <option value="<?= htmlspecialchars($navn) ?>" <?= $navn === $valgtServer ? "selected" : "" ?>>
                            <?= htmlspecialchars($navn) ?> (<?= htmlspecialchars($ip) ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </form>
        </section>

        <?php if ($syncStartet): ?>
            <p class="success">Manuell synkronisering ble startet på <?= htmlspecialchars($valgtServer) ?>.</p>
        <?php elseif ($_SERVER["REQUEST_METHOD"] === "POST"): ?>
            <p class="error">Kunne ikke starte manuell synkronisering på <?= htmlspecialchars($valgtServer) ?>.</p>
        <?php endif; ?>

        <section class="grid">
            <article class="card">
                <h2>Service</h2>
                <p class="status <?= htmlspecialchars($serviceCssClass) ?>">
                    <?= htmlspecialchars($serviceStatusVisning) ?>
                </p>

                    <?php if ($serviceForklaring): ?>
                        <p class="muted"><?= htmlspecialchars($serviceForklaring) ?></p>
                    <?php endif; ?>
            </article>

            <article class="card">
                <h2>Timer</h2>
                <p class="status <?= htmlspecialchars($timerStatus) ?>">
                    <?= htmlspecialchars($timerStatus) ?>
                </p>
            </article>

            <article class="card">
                <h2>Siste loggaktivitet</h2>
                <p><?= htmlspecialchars($sisteSyncTid) ?></p>
            </article>
        </section>

        <section class="card">
            <h2>Manuell synkronisering</h2>
            <p>Starter AD-sync via systemd service på <?= htmlspecialchars($valgtServer) ?>.</p>

            <form method="post">
                <input type="hidden" name="server" value="<?= htmlspecialchars($valgtServer) ?>">
                <button type="submit" name="start_sync" value="1">
                    Kjør sync nå
                </button>
            </form>
        </section>

        <section class="card">
            <h2>Siste logglinjer fra <?= htmlspecialchars($valgtServer) ?></h2>
            <pre><?php foreach ($loggLinjer as $linje): ?><?= htmlspecialchars($linje) . "\n" ?><?php endforeach; ?></pre>
        </section>
    </main>
</body>
</html>
