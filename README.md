# Atlas CMDB – telepíthető kiadás

Ez a mappa a futtatásra kész alkalmazást és a szükséges PHP-függőségeket tartalmazza. Node.js, pnpm és Composer használata nem szükséges.

## Első indítás XAMPP alatt

1. Indítsd el a XAMPP MariaDB/MySQL szolgáltatását.
2. Nyiss PowerShellt ebben a mappában.
3. Futtasd: `./Start-CMDB.ps1`
4. Nyisd meg: `http://127.0.0.1:8088/`
5. A böngészős telepítővel hozd létre az üres adatbázist, majd az első adminisztrátort.

A privát konfiguráció, a titkosítási kulcs, a sessionök, az importok, exportok és mentések alapértelmezetten a `C:\xampp\Atlas-cmdb-private` mappába kerülnek, tehát a webes könyvtáron kívül maradnak.

## Automatikus deploy és frissítés Apache 2.4 alatt

A deploy script a `C:\Apache24\htdocs\atlas` mappába telepít, létrehozza és beköti az Apache vhost fájlt, ellenőrzi az Apache-konfigurációt, meglévő rendszer esetén mentést készít, frissíti a fájlokat, migrálja az adatbázist, újraindítja az Apache szolgáltatást és az exportworkert, majd HTTP-állapotellenőrzést futtat. A már létező üres célmappát elfogadja. Nem üres, Git nélküli célmappát csak `-Force` használatakor cserél le, és előtte időbélyeges `.predeploy-*` biztonsági másolatként megőrzi.

```powershell
# Csak ellenőrzés, módosítás nélkül
.\Deploy-AtlasCMDB.ps1 -DryRun

# Telepítés vagy frissítés
.\Deploy-AtlasCMDB.ps1
```

Alapértelmezett cím: `http://atlas-cmdb.local/`. Ehhez adj `127.0.0.1 atlas-cmdb.local` sort a `C:\Windows\System32\drivers\etc\hosts` fájlhoz, vagy hozz létre helyi DNS-rekordot. Az Apache-ban a PHP-kezelőt és a `mod_env` modult előre be kell állítani. A scriptet rendszergazdai PowerShellből futtasd.

Másik Apache-telepítés, célmappa vagy cím:

```powershell
.\Deploy-AtlasCMDB.ps1 `
  -ApacheRoot 'D:\Apache24' `
  -TargetPath 'D:\Apache24\htdocs\atlas' `
  -ServerName 'atlas-cmdb.local' `
  -Port 80
```

Hasznos kapcsolók: `-VHostPath`, `-Config`, `-Branch`, `-ApacheServiceName`, `-NoRestart`, `-SkipBackup`. A `-Force` Git-repóban a helyi módosítások eldobását engedélyezi; nem Git-alapú, nem üres célmappánál a régi mappát biztonsági másolatba helyezi. A privát konfiguráció és az adatmentések nem kerülnek a Git repóba.

Kész vhost minta: `apache/atlas-cmdb.conf`. A deploy script alapértelmezetten a `C:\Apache24\conf\extra\atlas-cmdb.conf` fájlt generálja, és egyszer hozzáadja az `Include` sort a `httpd.conf` végéhez.

Másik port vagy külön konfigurációs hely használata:

```powershell
./Start-CMDB.ps1 -Port 8090 -Config 'C:\private\atlas-cmdb\config.json'
```

Apache használatakor a `public` mappa legyen a dokumentumgyökér. XAMPP helyi, engedélyezett `.htaccess` átírással a `http://localhost/Atlas-cmdb/` cím is használható.

## Karbantartás

- Adatbázis-migráció meglévő telepítés frissítése után: `C:\xampp\php\php.exe install.php`
- Mentés: `C:\xampp\php\php.exe backup.php`
- Lejárt exportok előnézete: `C:\xampp\php\php.exe maintenance.php`
- Lejárt exportok törlése: `C:\xampp\php\php.exe maintenance.php --apply`

A mentések titkokat is tartalmaznak. Tartsd őket biztonságos, nem publikus helyen.
