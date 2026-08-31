Set WshShell = CreateObject("WScript.Shell")
WshShell.Run "cmd.exe /c """ & "c:\Sistema de Ventas\iniciar-servidor.bat" & """", 0, False
Set WshShell = Nothing
