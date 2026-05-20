"""
AD Sync Script

Beskrivelse:
Synkroniserer brukere og grupper fra Active Directory til lokale Linux-brukere via LDAPS.

Funksjonalitet:
- Oppretter lokale Linux-brukere basert på aktive AD-brukere
- Aktiverer og deaktiverer lokale brukere basert på AD-status
- Synkroniserer gruppemedlemskap for AD-grupper med prefiks linux-
- Utfører avvikssjekk mellom AD og lokal Linux-tilstand etter synkronisering

Krav:
- Må kjøres som root
- Krever miljøvariabler fra /etc/ad-sync/ad-sync.env

Merk:
- Active Directory er autoritativ kilde for brukere og gruppemedlemskap
- Lokale system- og administrasjonsbrukere i LOCAL_SKIP påvirkes ikke
"""

import logging
import os
import re
import ssl
import subprocess

from ldap3 import ALL, Connection, Server, Tls

# LDAP-tilkobling hentes fra ad-sync.env
LDAP_SERVER = os.environ["LDAP_SERVER"]
BASE_DN = os.environ["BASE_DN"]
USER = os.environ["LDAP_BIND_USER"]
PASSWORD = os.environ["LDAP_BIND_PASSWORD"]

# Egne søkebaser kan settes i miljøfilen, ellers brukes BASE_DN
AD_USER_BASE_DN = os.environ.get("AD_USER_BASE_DN", BASE_DN)
AD_GROUP_BASE_DN = os.environ.get("AD_GROUP_BASE_DN", BASE_DN)
GROUP_PREFIX = os.environ.get("GROUP_PREFIX", "linux-")

# Brukere som aldri skal behandles som vanlige AD-brukere
SKIP_AD_USERS = {"administrator", "guest", "krbtgt", "svc_linux_sync"}

# Lokale brukere som ikke skal deaktiveres eller fjernes fra grupper av sync-scriptet
LOCAL_SKIP = {"root", "andreas", "nobody", "svc_linux_sync", "ansible", "websync"}


# Logger både til fil og stdout slik at status kan leses via journalctl og loggfil
logging.basicConfig(
    filename="/var/log/ad-sync.log",
    level=logging.INFO,
    format="%(asctime)s %(levelname)s %(message)s",
)


def log(msg):
    print(msg)
    logging.info(msg)


# Tillater kun brukernavn og gruppenavn som Linux håndterer trygt
# Dette hindrer at ugyldige AD-navn brukes direkte i lokale kommandoer
def valid_linux_name(name):
    return bool(re.fullmatch(r"[a-z_][a-z0-9_.-]{0,31}", name))


# Kjører lokale Linux-kommandoer og logger feil uten å stoppe hele synkroniseringen
# Returverdien brukes videre for å avgjøre om kommandoen var vellykket
def run(cmd):
    result = subprocess.run(cmd, capture_output=True, text=True)

    if result.returncode != 0 and result.stderr:
        logging.warning(f"Kommando {cmd[0]} feilet: {result.stderr.strip()}")

    return result


# Sjekker om lokal bruker er låst med passwd -S
# L = passordet/kontoen er låst
def bruker_er_laas(u):
    resultat = run(["passwd", "-S", u])

    if resultat.returncode != 0:
        return False

    felt = resultat.stdout.split()

    return len(felt) > 1 and felt[1] == "L"


# Henter shell for en lokal Linux-bruker fra passwd-databasen
def hent_shell(u):
    resultat = run(["getent", "passwd", u])

    if resultat.returncode != 0:
        return None

    felt = resultat.stdout.strip().split(":")

    if len(felt) < 7:
        return None

    return felt[6]


# Oppretter LDAPS-tilkobling mot Active Directory
# CA-sertifikatet må være installert på Linux-serveren for at valideringen skal fungere
def koble_til():
    tls = Tls(validate=ssl.CERT_REQUIRED)
    server = Server(LDAP_SERVER, use_ssl=True, get_info=ALL, tls=tls)
    return Connection(server, user=USER, password=PASSWORD, auto_bind=True)


# Henter AD-brukere og returnerer brukernavn med aktiv/deaktiv status
# Deaktiverte AD-kontoer tas med, slik at lokale brukere kan deaktiveres tilsvarende
def hent_ad_brukere(conn):
    conn.search(
        AD_USER_BASE_DN,
        "(objectClass=user)",
        attributes=["sAMAccountName", "userAccountControl"],
    )

    brukere = {}

    for e in conn.entries:
        u = e.sAMAccountName.value

        if not u:
            continue

        u = u.lower()

        if u in SKIP_AD_USERS or u.endswith("$"):
            continue

        if not valid_linux_name(u):
            log(f"[!] Hopper over ugyldig brukernavn: {u}")
            continue

        uac = e.userAccountControl.value or 0

        # Bit 2 i userAccountControl betyr at AD-kontoen er deaktivert
        er_aktiv = not (uac & 2)

        brukere[u] = er_aktiv

    return brukere


# Henter AD-grupper som skal synkroniseres til lokale Linux-grupper
# Kun grupper med definert prefiks behandles av scriptet
def hent_ad_grupper(conn):
    conn.search(
        AD_GROUP_BASE_DN,
        "(objectClass=group)",
        attributes=["cn", "member"],
    )

    grupper = {}

    for e in conn.entries:
        gruppenavn = e.cn.value

        if not gruppenavn:
            continue

        gruppenavn = gruppenavn.lower()

        if not gruppenavn.startswith(GROUP_PREFIX):
            continue

        if not valid_linux_name(gruppenavn):
            log(f"[!] Hopper over ugyldig gruppenavn: {gruppenavn}")
            continue

        medlemmer = []

        # Slår opp hvert gruppemedlem for å hente sAMAccountName fra DN
        if e.member:
            for dn in e.member.values:
                conn.search(dn, "(objectClass=user)", attributes=["sAMAccountName"])

                if conn.entries:
                    u = conn.entries[0].sAMAccountName.value

                    if u:
                        u = u.lower()

                        if valid_linux_name(u) and u not in SKIP_AD_USERS:
                            medlemmer.append(u)

        grupper[gruppenavn] = medlemmer

    return grupper


# Henter lokale brukere med UID 1000 eller høyere
# Systembrukere og faste administrasjonsbrukere filtreres bort
def hent_lokale_brukere():
    resultat = run(["getent", "passwd"])
    brukere = set()

    for linje in resultat.stdout.splitlines():
        deler = linje.split(":")
        u = deler[0]
        uid = int(deler[2])

        if uid >= 1000 and u not in LOCAL_SKIP:
            brukere.add(u)

    return brukere


# Oppretter lokal Linux-bruker med hjemmemappe og bash-shell
def opprett_bruker(u):
    run(["useradd", "-m", "-s", "/bin/bash", u])
    log(f"[+] Opprettet bruker: {u}")


# Aktiverer lokal bruker ved å sette shell tilbake til bash
def aktiver_bruker(u):
    if hent_shell(u) != "/bin/bash":
        run(["usermod", "--shell", "/bin/bash", u])
        log(f"[↑] Aktivert bruker: {u}")


# Deaktiverer lokal bruker ved å låse kontoen og sette nologin-shell
# Brukeren slettes ikke, slik at hjemmemappe og historikk beholdes
def deaktiver_bruker(u):
    endret = False

    if not bruker_er_laas(u):
        run(["usermod", "--lock", u])
        endret = True

    if hent_shell(u) != "/sbin/nologin":
        run(["usermod", "--shell", "/sbin/nologin", u])
        endret = True

    if endret:
        log(f"[-] Deaktivert bruker: {u}")


# Oppretter lokal Linux-gruppe dersom den ikke finnes fra før
def sikre_gruppe(gruppenavn):
    if run(["getent", "group", gruppenavn]).returncode != 0:
        run(["groupadd", gruppenavn])
        log(f"[+] Opprettet gruppe: {gruppenavn}")


# Henter lokale medlemmer av en Linux-gruppe
def hent_lokale_gruppemedlemmer(gruppenavn):
    resultat = run(["getent", "group", gruppenavn])

    if resultat.returncode != 0:
        return set()

    felt = resultat.stdout.strip().split(":")

    if len(felt) < 4 or not felt[3]:
        return set()

    return set(felt[3].split(","))


# Synkroniser gruppemedlemmer mot tilsvarende AD gruppe
# Brukere legges kun til dersom de finnes lokalt på serveren
def synkroniser_gruppe(gruppenavn, ønskede_medlemmer):
    sikre_gruppe(gruppenavn)

    ønskede = set(ønskede_medlemmer)
    lokale = hent_lokale_gruppemedlemmer(gruppenavn)

    for u in ønskede - lokale:
        if run(["id", u]).returncode == 0:
            run(["usermod", "-aG", gruppenavn, u])
            log(f"[G+] La {u} til i {gruppenavn}")

    for u in lokale - ønskede:
        if u not in LOCAL_SKIP:
            run(["gpasswd", "-d", u, gruppenavn])
            log(f"[G-] Fjernet {u} fra {gruppenavn}")


# Kontrollerer etter synkronisering om lokal tilstand fortsatt avviker fra AD
# Avvik logges for feilsøking og sporbarhet
def sjekk_avvik(ad_brukere, ad_grupper):
    lokale_brukere = hent_lokale_brukere()

    for brukernavn, er_aktiv in ad_brukere.items():
        if er_aktiv and brukernavn not in lokale_brukere:
            log(f"[AVVIK] Aktiv AD-bruker mangler lokalt: {brukernavn}")

    for brukernavn in lokale_brukere:
        if brukernavn not in ad_brukere and brukernavn not in LOCAL_SKIP:
            log(f"[AVVIK] Lokal bruker finnes ikke i AD: {brukernavn}")

    for gruppenavn, ønskede_medlemmer in ad_grupper.items():
        lokale_medlemmer = hent_lokale_gruppemedlemmer(gruppenavn)
        ønskede = set(ønskede_medlemmer)

        for brukernavn in ønskede - lokale_medlemmer:
            if brukernavn in ad_brukere and ad_brukere[brukernavn]:
                log(f"[AVVIK] {brukernavn} mangler i lokal gruppe {gruppenavn}")

        for brukernavn in lokale_medlemmer - ønskede:
            if brukernavn not in LOCAL_SKIP:
                log(f"[AVVIK] {brukernavn} er lokalt medlem av {gruppenavn}, men ikke i AD")


# Henter informasjon fraAD, oppdaterer lokale brukere og synkroniserer grupper
def main():
    if os.geteuid() != 0:
        print("[FEIL] Scriptet må kjøres som root (sudo)")
        logging.error("Scriptet ble ikke kjørt som root")
        exit(1)

    log("=== AD-sync startet ===")

    try:
        conn = koble_til()
    except Exception as e:
        logging.error(f"Kunne ikke koble til LDAPS: {e}")
        print(f"[FEIL] {e}")
        return

    ad_brukere = hent_ad_brukere(conn)
    log(f"[INFO] {len(ad_brukere)} brukere hentet fra AD")

    lokale_brukere = hent_lokale_brukere()

    # Oppretter, aktiverer eller deaktiverer lokale brukere basert på AD-status
    for brukernavn, er_aktiv in ad_brukere.items():
        if brukernavn not in lokale_brukere:
            if er_aktiv:
                opprett_bruker(brukernavn)
        else:
            if er_aktiv:
                aktiver_bruker(brukernavn)
            else:
                deaktiver_bruker(brukernavn)

    # Lokale brukere som ikke finnes i AD deaktiveres som sikkerhetstiltak
    for u in lokale_brukere:
        if u not in ad_brukere and u not in LOCAL_SKIP:
            log(f"[!] Lokal bruker finnes ikke i AD: {u}")
            deaktiver_bruker(u)

    ad_grupper = hent_ad_grupper(conn)

    # Synkroniserer lokale Linux-grupper mot tilsvarende AD-grupper
    for gruppenavn, medlemmer in ad_grupper.items():
        synkroniser_gruppe(gruppenavn, medlemmer)

    sjekk_avvik(ad_brukere, ad_grupper)

    log("=== AD-sync ferdig ===")


if __name__ == "__main__":
    main()
