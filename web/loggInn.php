<?php
/*
Midtbyen VGS - Innlogging til AD-sync administrasjonspanel

Bruker session-basert autentisering for å beskytte statuspanelet
*/

session_start();

$feilmelding = "";

/*
Enkel lokal admin-bruker for prosjektets administrasjonspanel

Passord lagres som hash med password_hash()
password_verify() brukes ved innlogging for sikker validering
*/
$adminBruker = "admin";
$adminPassordHash = '$2y$10$FE7L7hKE5WxXCPPWB4c/wuy5t94jQ/dJJO.SRdMfCmUS7mK9/DFb.';

/*
Innlogging behandles kun ved POST-request
*/
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    $brukernavn = trim($_POST["brukernavn"] ?? "");
    $passord = $_POST["passord"] ?? "";


    //Validerer brukernavn og passord mot lagret hash, ved vellykket innlogging opprettes session
    if ($brukernavn === $adminBruker && password_verify($passord, $adminPassordHash)) {
        $_SESSION["innlogget"] = true;
        $_SESSION["brukernavn"] = $brukernavn;

        header("Location: index.php");
        exit();
    }

    $feilmelding = "Feil brukernavn eller passord.";
}
?>

<!doctype html>
<html lang="no">
<head>
    <meta charset="utf-8">
    <title>Midtbyen VGS - Innlogging</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>
    <main class="login-container">
        <section class="card login-card">
            <h1>Midtbyen VGS</h1>
            <p>Administrasjonspanel for AD-sync</p>

            <?php if ($feilmelding !== ""): ?>
                <!-- htmlspecialchars brukes for å hindre HTML/script-injeksjon -->
                <p class="error"><?= htmlspecialchars($feilmelding) ?></p>
            <?php endif; ?>

            <form method="post">
                <label for="brukernavn">Brukernavn</label>
                <input type="text" id="brukernavn" name="brukernavn" required>

                <label for="passord">Passord</label>
                <input type="password" id="passord" name="passord" required>

                <button type="submit">Logg inn</button>
            </form>
        </section>
    </main>
</body>
</html>
