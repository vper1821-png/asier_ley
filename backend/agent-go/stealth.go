package main

import (
	"os/exec"
	"runtime"
	"time"
)

func applyStealth() {
	if runtime.GOOS == "windows" {
		hideConsole()
	}
}

func hideConsole() {
	exec.Command("powershell", "-NoProfile", "-Command",
		"Add-Type -Name Win -Namespace Console -MemberDefinition '[DllImport(\"kernel32.dll\")] public static extern IntPtr GetConsoleWindow(); [DllImport(\"user32.dll\")] public static extern bool ShowWindow(IntPtr hWnd, int nCmdShow);'; $h = [Console.Win]::GetConsoleWindow(); [Console.Win]::ShowWindow($h, 0);",
	).Run()
}

var startTime = time.Now()
