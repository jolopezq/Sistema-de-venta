Set WshShell = CreateObject("WScript.Shell")
WshShell.Run "cmd.exe /c """ & "c:\Sistema de Ventas\vigilante-actualizaciones.bat" & """", 0, False
Set WshShell = Nothing
