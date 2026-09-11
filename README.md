# Atlas CMDB – telepíthető kiadás

Ez a mappa a futtatásra kész alkalmazást és a szükséges PHP-függőségeket tartalmazza. Node.js, pnpm és Composer használata nem szükséges.

## Első indítás XAMPP alatt

1. Indítsd el a XAMPP MariaDB/MySQL szolgáltatását.
2. Nyiss PowerShellt ebben a mappában.
3. Futtasd: `./Start-CMDB.ps1`
4. Nyisd meg: `http://127.0.0.1:8088/`
5. A böngészős telepítővel hozd létre az üres adatbázist, majd az első adminisztrátort.

A privát konfiguráció, a titkosítási kulcs, a sessionök, az importok, exportok és mentések alapértelmezetten a `C:\xampp\Atlas-cmdb-private` mappába kerülnek, tehát a webes könyvtáron kívül maradnak.

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
