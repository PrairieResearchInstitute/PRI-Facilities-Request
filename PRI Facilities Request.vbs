' PRI Facilities Request System - double-click to launch.
'
' Three things this launcher will not do, because each has cost a day:
'
'   It does not assume where PHP is. The path used to be written in
'   here, so opening the app on a second computer produced only 'PHP
'   was not found' with no hint of where it had looked. It now searches,
'   starting with the copy inside the app folder, which travels.
'
'   It does not assume that something answering the port is us. It asks
'   health.php which folder is answering, because a leftover server from
'   another copy once served stale code and looked like an app bug.
'
'   And it does not treat 'nothing answered' as 'the port is free'.
'   Windows lets php -S bind a port another server already holds, then
'   gives connections to one of them at random - so our server really
'   did start on 8080 underneath another project's, the health check
'   reached theirs, and this launcher said the server never came up.
'   Only a TCP listener check settles whether a port is free.

Option Explicit
Dim sh, fso, appDir, php, root, tries, tried
Dim basePort, maxPort, prt, started, chosenUrl, freePort
Dim probeAnswered, probeText

Set sh  = CreateObject("WScript.Shell")
Set fso = CreateObject("Scripting.FileSystemObject")

appDir = fso.GetParentFolderName(WScript.ScriptFullName)
root   = Replace(appDir & "\facilities", "\", "/")
basePort = 8080
maxPort  = 8089

' ---- find PHP -------------------------------------------------------
tried = ""
php = FindPhp()

Function Try(path)
    Try = ""
    tried = tried & "  " & path & vbCrLf
    If fso.FileExists(path) Then Try = path
End Function

' The scratch folders the server must use, as -d arguments for php -S.
' Standard v2 section B: nothing on the computer. sys_temp_dir and
' upload_tmp_dir cannot be set from inside a page, so they are set here or
' not at all, and PHP's default puts every uploaded photograph in the
' profile temp folder on C: on its way into storage. An empty string back
' from the helper means the drive is unreachable, and the server starts
' without the overrides rather than not at all: the drive has its own
' message, and a launcher that refuses to run teaches nothing.
Function RuntimeArgs()
    Dim e, out, parts
    RuntimeArgs = ""
    out = ""
    On Error Resume Next
    Set e = sh.Exec("""" & php & """ """ & appDir & "\tools\runtime_dirs.php""")
    If Err.Number <> 0 Then Exit Function
    Do While Not e.StdOut.AtEndOfStream
        out = out & e.StdOut.ReadLine & vbLf
    Loop
    On Error GoTo 0
    parts = Split(out, vbLf)
    If UBound(parts) < 2 Then Exit Function
    If Trim(parts(0)) = "" Or Trim(parts(1)) = "" Or Trim(parts(2)) = "" Then Exit Function
    RuntimeArgs = IniArg("sys_temp_dir", Trim(parts(0))) & _
                  IniArg("upload_tmp_dir", Trim(parts(1))) & _
                  IniArg("session.save_path", Trim(parts(2)))
End Function

' One -d argument, as -d "key=\"value\"".
'
' The quotes inside the value are not decoration. PHP parses a -d value as
' ini text, so an unquoted one breaks on any character the ini syntax
' claims. A path through a short Windows name, %USERPROFILE% written as
' ...\Users\SAUGAD~1\..., was truncated at the tilde and PHP then used
' ...\Users\SAUGAD, printing a
' parse error no launcher reads and carrying on with the wrong folder. A
' space does the same. Proven both ways on 8 Oct 2026 before this form was
' chosen.
Function IniArg(k, v)
    Dim q, bs
    q  = Chr(34)
    bs = Chr(92)
    IniArg = " -d " & q & k & "=" & bs & q & v & bs & q & q
End Function

Function FindPhp()
    Dim p, f
    FindPhp = ""
    ' 1. Bundled with the app, so it works wherever the folder is.
    p = Try(appDir & "\tools\php\php.exe")
    If p <> "" Then FindPhp = p : Exit Function
    ' 2. A path you wrote in php-path.txt beside this launcher.
    If fso.FileExists(appDir & "\php-path.txt") Then
        Set f = fso.OpenTextFile(appDir & "\php-path.txt", 1)
        p = Trim(f.ReadLine)
        f.Close
        p = Try(p)
        If p <> "" Then FindPhp = p : Exit Function
    End If
    ' 3. Anything on PATH.
    '    A path on one developer's C: drive used to sit here. It went on
    '    8 Oct 2026: the app must run from the drive with nothing of its
    '    own on the computer, and the bundled copy at step 1 carries
    '    pdo_sqlsrv, so the fallback only ever hid a missing bundle.
    On Error Resume Next
    p = sh.Exec("cmd /c where php").StdOut.ReadLine
    On Error GoTo 0
    If p <> "" Then
        p = Try(Trim(p))
        If p <> "" Then FindPhp = p
    End If
End Function

If php = "" Then
    MsgBox "PHP was not found. Looked in:" & vbCrLf & vbCrLf & tried & vbCrLf & _
           "Install PHP 8.2+ with the pdo_sqlsrv extension, or write its" & vbCrLf & _
           "full path into a file called php-path.txt beside this launcher.", _
           vbExclamation, "PRIOps"
    WScript.Quit 1
End If

' ---- wake LocalDB before PHP ever touches it ------------------------
' LocalDB stops itself after idling. On 17 Sep a server started while the
' instance had been stopped for a week; its first connection failed with
' 'Login failed' and that process never recovered, although a fresh one
' connected at once. Starting the instance first means no server ever
' makes first contact with a stopped instance. Only when config.php points
' at LocalDB - once the databases live on a real server this is skipped.
Dim cfgText
cfgText = ""
On Error Resume Next
If fso.FileExists(appDir & "\config.php") Then cfgText = fso.OpenTextFile(appDir & "\config.php", 1).ReadAll
' Which engine holds the registers (dataMode in config.local.json, read by
' tools\data_mode.php). On PostgreSQL every LocalDB step below is skipped,
' and PHP gets pdo_pgsql, which the bundled php.ini does not load.
Dim engine, useLocalDb, pgExt
engine = ""
pgExt = ""
engine = LCase(Trim(sh.Exec("""" & php & """ """ & appDir & "\tools\data_mode.php""").StdOut.ReadLine))
If engine = "postgres" Then pgExt = " -d extension=pdo_pgsql"
useLocalDb = (engine <> "postgres") And (InStr(1, cfgText, "(localdb)", vbTextCompare) > 0)
If useLocalDb Then sh.Run "powershell -NoProfile -Command $i=(sqllocaldb info MSSQLLocalDB) -join ' ';if($i -notmatch 'Running'){sqllocaldb start MSSQLLocalDB 2>$null | Out-Null;$i=(sqllocaldb info MSSQLLocalDB) -join ' ';if($i -notmatch 'Running'){Get-CimInstance Win32_Process -Filter ""Name='sqlservr.exe'"" | Where-Object {$_.CommandLine -like '*LocalDB*'} | ForEach-Object {Stop-Process -Id $_.ProcessId -Force};sqllocaldb start MSSQLLocalDB 2>$null | Out-Null}}", 0, True
' Running is not the same as answering. The launcher used to start the
' instance and then start the server AND the keep-alive within a second,
' so two processes made first contact while it was still settling - which
' is how a second engine gets spawned and then lost, leaving the instance
' reporting Stopped while an orphan holds it. Wait for one proven query -
' briefly, and never let the wait stop the application starting. The app
' wakes and retries by itself, and a slow database is no reason to leave
' somebody staring at nothing.
Dim readyTries, dbReady
dbReady = False
If useLocalDb Then
    For readyTries = 1 To 6
        If sh.Run("""" & php & """ """ & appDir & "\tools\dbready.php"" 5", 0, True) = 0 Then
            dbReady = True
            Exit For
        End If
        WScript.Sleep 1500
    Next
End If

On Error GoTo 0

' ---- PostgreSQL on the drive -----------------------------------------
' On PostgreSQL the registers live in the estate cluster on the drive
' (platform D-028, app D-011): 127.0.0.1 port 5433, program and data on
' the drive, nothing installed on the computer. Start it before the
' server. tools\pg\cluster.php runs node estate-pg.js when a node.exe is
' on the drive or on PATH, and the drive's pg_ctl otherwise. Window style
' 0 keeps it hidden; the call waits for the cluster to answer.
If engine = "postgres" Then
    If sh.Run("""" & php & """ """ & appDir & "\tools\pg\cluster.php"" start", 0, True) <> 0 Then
        MsgBox "PostgreSQL on the drive did not start, so the application" & vbCrLf & _
               "cannot open its registers." & vbCrLf & vbCrLf & _
               "Run start-local.bat to see what the drive's PostgreSQL reports.", _
               vbExclamation, "PRIOps"
        WScript.Quit 1
    End If
End If

' ---- ports ----------------------------------------------------------
Function PortUrl(n)
    ' 127.0.0.1 explicitly, never localhost. localhost resolves to ::1
    ' first on Windows and 127.0.0.1 second, so one URL can reach two
    ' different servers sitting on the same port number.
    PortUrl = "http://127.0.0.1:" & n & "/"
End Function

' Two separate questions: did anything answer at all, and was it us?
' health.php names the folder answering and touches no database, so it
' replies even when SQL is down.
Sub Probe(n)
    Dim http
    probeAnswered = False
    probeText     = ""
    On Error Resume Next
    Set http = CreateObject("MSXML2.ServerXMLHTTP.6.0")
    http.SetTimeouts 700, 700, 1500, 1500
    http.Open "GET", PortUrl(n) & "health.php", False
    http.Send
    If Err.Number = 0 Then
        probeAnswered = True
        If http.Status = 200 Then probeText = http.responseText
    End If
    On Error GoTo 0
End Sub

' A port is free only if nothing is LISTENING on it - any address family.
' Ask Windows once for every listening port in our range, rather than
' knocking on all ten over HTTP: a knock at a dead port costs a couple of
' seconds of timeout, which turned a double-click into a 23 second wait.
Function ListeningPorts()
    Dim ex
    ListeningPorts = ""
    On Error Resume Next
    Set ex = sh.Exec("powershell -NoProfile -Command ""(Get-NetTCPConnection -State Listen -ErrorAction SilentlyContinue | Where-Object {$_.LocalPort -ge " & basePort & " -and $_.LocalPort -le " & maxPort & "} | Select-Object -ExpandProperty LocalPort -Unique | Sort-Object) -join ','""")
    ListeningPorts = Trim(ex.StdOut.ReadAll())
    On Error GoTo 0
    ListeningPorts = Replace(Replace(ListeningPorts, vbCr, ""), vbLf, "")
End Function

Function IsListening(n, list)
    IsListening = (InStr(1, "," & list & ",", "," & n & ",") > 0)
End Function

started   = False
chosenUrl = ""
Dim busy
busy = ListeningPorts()

' 1. Already running from this folder? Only occupied ports can be us.
For prt = basePort To maxPort
    If IsListening(prt, busy) Then
        Probe prt
        If InStr(1, probeText, root, vbTextCompare) > 0 Then
            sh.Run PortUrl(prt), 1, False
            WScript.Quit 0
        End If
    End If
Next

' 2. Otherwise take the first port with nothing listening on it at all.
freePort = 0
For prt = basePort To maxPort
    If Not IsListening(prt, busy) Then
        freePort = prt
        Exit For
    End If
Next

If freePort = 0 Then
    MsgBox "Every port from " & basePort & " to " & maxPort & " is in use by" & vbCrLf & _
           "another program, so there is nowhere to start the server." & vbCrLf & vbCrLf & _
           "Close one of them and launch this again.", _
           vbExclamation, "PRIOps"
    WScript.Quit 1
End If

sh.Run """" & php & """" & pgExt & RuntimeArgs() & " -S 127.0.0.1:" & freePort & " -t """ & appDir & "\facilities""", 0, False

tries = 0
Do
    WScript.Sleep 400
    tries = tries + 1
    Probe freePort
Loop Until probeText <> "" Or tries >= 10

If InStr(1, probeText, root, vbTextCompare) > 0 Then
    chosenUrl = PortUrl(freePort)
    started   = True
End If

If Not started Then
    MsgBox "The server did not come up on port " & freePort & "." & vbCrLf & vbCrLf & _
           "PHP was found at:" & vbCrLf & "  " & php & vbCrLf & vbCrLf & _
           "Run start-local.bat to see the error it printed.", _
           vbExclamation, "PRIOps"
    WScript.Quit 1
End If

sh.Run chosenUrl, 1, False

' Keep LocalDB attached while the application is open. Without this it
' idles out, restarts with a new pipe, and the server already running
' is left holding the old one - which is the "Login failed" that kept
' coming back. The keep-alive watches the port and exits with the app.
If useLocalDb Then
    sh.Run """" & php & """ """ & appDir & "\tools\keepalive.php"" " & freePort, 0, False
End If

' On PostgreSQL a hidden watcher stops the drive's cluster cleanly once
' this server stops answering, unless another app is still connected, so
' the drive can be ejected after the last app closes.
If engine = "postgres" Then
    sh.Run """" & php & """ -d extension=pdo_pgsql """ & appDir & "\tools\pg\cluster.php"" watch " & freePort, 0, False
End If

