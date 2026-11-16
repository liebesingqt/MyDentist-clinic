# Putting MyDentist online with InfinityFree

The online copy runs on InfinityFree's free hosting with a **MySQL** database
(managed in their phpMyAdmin). Your laptop copy keeps using SQL Server and SSMS.

Use the upload-ready package **mydentist-infinityfree.zip**. Everything inside it goes
straight into the `htdocs` folder.

## 1. Account
1. Go to https://www.infinityfree.com and register with your Gmail. Confirm their email.
2. Click **Create Account**, choose a free subdomain (for example `mydentist-clinic`) and finish.

## 2. Check the host
1. Open the account's **File Manager** and go into **htdocs**.
2. Upload `hostcheck.php` and open `http://YOUR-ADDRESS/hostcheck.php`.
3. PHP version, MySQL driver and "Can save files" should be green.
   "Can connect out for email" should be green for real emails to work.
4. Delete `hostcheck.php` from the hosting afterwards.

## 3. Create the database
In the control panel open **MySQL Databases**, create a database named `mydentist`,
and note these four values:

| Value | Looks like |
|---|---|
| MySQL hostname | `sql123.infinityfree.com` |
| Database name | `if0_12345678_mydentist` |
| Username | `if0_12345678` |
| Password | your hosting account password (shown in the account details) |

## 4. Fill in the settings file
1. Unzip **mydentist-infinityfree.zip** on your PC.
2. Open `config.local.php` in Notepad.
3. Replace the four database values and the website address (`APP_URL`). Save.

Do not change `config.php`.

## 5. Upload
Upload **all the unzipped files and folders** into `htdocs`, so that `index.php` sits
directly inside `htdocs`. If the browser File Manager struggles with many files, use the free
program FileZilla with the FTP details from the control panel.

If `htdocs` already contains a default `index2.html` or similar placeholder, delete it.

## 6. Install and secure
1. Open your website address and click **Install now**.
2. Log in as `admin` / `Admin@123`, then as `staff` / `Staff@123`, and **change both
   passwords** in Settings straight away.
3. In Admin → Settings → **Reset Patient Password**, give the sample patient `maria` a new
   password.
4. In Admin → Settings → **Email**, enter the Gmail address and App Password, switch on
   **Send real emails**, save and send a test.
5. If the control panel offers a free SSL certificate, turn it on, then change `APP_URL` in
   `config.local.php` to start with `https://`.

## If a page is blank or shows an error
Open `config.local.php`, remove the `//` in front of `define('APP_DEBUG', true);`, upload it
again and reload the page. It will show the exact error. Put the `//` back afterwards.

## Good to know
- There are no scheduled tasks on free hosting. Reminder emails (24, 12 and 6 hours before)
  are sent when Admin or Staff open a page, so open the dashboard a few times a day.
- The online database can only be opened from InfinityFree's phpMyAdmin.
- Set the clinic's real phone and email by adding lines like these to `config.local.php`:

```php
define('CLINIC_PHONE', '+63 9XX XXX XXXX');
define('CLINIC_EMAIL', 'clinic@gmail.com');
```
