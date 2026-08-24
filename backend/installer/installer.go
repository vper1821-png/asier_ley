// SecureLab Agent Installer - Go Installer con GUI usando Fyne
// Compilar: set CGO_ENABLED=1 && go build -ldflags "-H windowsgui -s -w" -o SecureLabAgent-Setup.exe installer.go

package main

import (
	"image/color"
	"io/ioutil"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"time"

	"fyne.io/fyne/v2"
	"fyne.io/fyne/v2/app"
	"fyne.io/fyne/v2/canvas"
	"fyne.io/fyne/v2/container"
	"fyne.io/fyne/v2/dialog"
	"fyne.io/fyne/v2/layout"
	"fyne.io/fyne/v2/theme"
	"fyne.io/fyne/v2/widget"
)

const (
	ProductName = "SecureLab Agent"
	InstallDir  = `C:\Program Files\SecureLab Agent`
	ServiceName = "SecureLabAgent"
)

type Config struct {
	APIBase  string `json:"api_base"`
	WSURL    string `json:"ws_url"`
	Token    string `json:"token"`
}

func main() {
	a := app.New()
	a.Settings().SetTheme(&CustomTheme{})

	w := a.NewWindow("SecureLab Agent - Instalador")
	w.Resize(fyne.NewSize(600, 500))
	w.CenterOnScreen()
	w.SetFixedSize(true)

	// Logo
	logoLabel := widget.NewLabel("🔒")
	logoLabel.Alignment = fyne.TextAlignCenter
	logoLabel.TextStyle = fyne.TextStyle{Bold: true}

	titleLabel := widget.NewLabel("SecureLab Agent")
	titleLabel.Alignment = fyne.TextAlignCenter
	titleLabel.TextStyle = fyne.TextStyle{Bold: true}

	subtitleLabel := widget.NewLabel("Instalador v2.0.0")
	subtitleLabel.Alignment = fyne.TextAlignCenter

	// Progress bar
	progress := widget.NewProgressBar()
	statusLabel := widget.NewLabel("Listo para instalar")
	logLabel := widget.NewLabel("")
	logLabel.Wrapping = fyne.TextWrapWord

	// Install button
	installBtn := widget.NewButton("Instalar", func() {
		statusLabel.SetText("Instalando...")
		progress.SetValue(0)
		go doInstall(w, progress, statusLabel, logLabel)
	})
	installBtn.Importance = widget.HighImportance

	// Close button
	closeBtn := widget.NewButton("Cerrar", func() {
		a.Quit()
	})

	// Header con logo
	header := container.NewVBox(
		container.NewCenter(logoLabel),
		container.NewCenter(titleLabel),
		container.NewCenter(subtitleLabel),
		widget.NewSeparator(),
	)

	content := container.NewVBox(
		header,
		widget.NewCard("Instalación", container.NewVBox(
			widget.NewLabel("Estado:"),
			statusLabel,
			progress,
		)),
		widget.NewLabel("Log de instalación:"),
		container.NewScroll(logLabel),
		container.NewHBox(
			layout.NewSpacer(),
			installBtn,
			closeBtn,
		),
	)

	w.SetContent(content)
	w.ShowAndRun()
}

type CustomTheme struct{}

func (m *CustomTheme) Color(name fyne.ThemeColorName, variant fyne.ThemeVariant) color.Color {
	if name == theme.ColorNameButton {
		return color.RGBA{R: 59, G: 130, B: 246, A: 255} // Azul
	}
	if name == theme.ColorNameBackground {
		return color.RGBA{R: 30, G: 30, B: 35, A: 255} // Fondo oscuro
	}
	if name == theme.ColorNameForeground {
		return color.RGBA{R: 255, G: 255, B: 255, A: 255} // Texto blanco
	}
	return theme.DefaultTheme().Color(name, variant)
}

func (m *CustomTheme) Font(style fyne.TextStyle) fyne.Resource {
	return theme.DefaultTheme().Font(style)
}

func (m *CustomTheme) Icon(name fyne.ThemeIconName) fyne.Resource {
	return theme.DefaultTheme().Icon(name)
}

func (m *CustomTheme) Size(name fyne.ThemeSizeName) float32 {
	return theme.DefaultTheme().Size(name)
}

func doInstall(w fyne.Window, progress *widget.ProgressBar, statusLabel, logLabel *widget.Label) {
	log := func(msg string) {
		logLabel.SetText(logLabel.Text + "\n" + msg)
	}

	log("Iniciando instalación...")
	
	// 1. Crear directorio
	statusLabel.SetText("Creando directorio...")
	progress.SetValue(0.25)
	log("[1/4] Creando directorio de instalación...")
	time.Sleep(500 * time.Millisecond)
	
	if err := os.MkdirAll(InstallDir, 0755); err != nil {
		dialog.ShowError(err, w)
		statusLabel.SetText("Error")
		return
	}
	log("  ✓ Directorio creado")

	// 2. Descargar binario
	statusLabel.SetText("Descargando agente...")
	progress.SetValue(0.5)
	log("[2/4] Descargando agente...")
	time.Sleep(500 * time.Millisecond)
	
	binaryPath := filepath.Join(InstallDir, "securelab-agent.exe")
	if err := downloadBinary(binaryPath); err != nil {
		dialog.ShowError(err, w)
		statusLabel.SetText("Error")
		return
	}
	log("  ✓ Binario descargado")

	// 3. Generar configuración
	statusLabel.SetText("Generando configuración...")
	progress.SetValue(0.75)
	log("[3/4] Generando configuración...")
	time.Sleep(500 * time.Millisecond)
	
	configPath := filepath.Join(InstallDir, "config.json")
	if err := generateConfig(configPath); err != nil {
		dialog.ShowError(err, w)
		statusLabel.SetText("Error")
		return
	}
	log("  ✓ Configuración generada")

	// 4. Crear e iniciar servicio
	statusLabel.SetText("Configurando servicio...")
	progress.SetValue(1.0)
	log("[4/4] Configurando servicio...")
	time.Sleep(500 * time.Millisecond)
	
	if err := setupService(binaryPath, configPath); err != nil {
		dialog.ShowError(err, w)
		statusLabel.SetText("Error")
		return
	}
	log("  ✓ Servicio configurado e iniciado")

	statusLabel.SetText("¡Instalación completada!")
	dialog.ShowInformation("Instalación Exitosa", "SecureLab Agent se ha instalado correctamente.", w)
}

func downloadBinary(destPath string) error {
	resp, err := http.Get("http://localhost:8090/api/agents/download-binary?platform=win-x64")
	if err != nil {
		return err
	}
	defer resp.Body.Close()

	if resp.StatusCode != http.StatusOK {
		return err
	}

	data, _ := ioutil.ReadAll(resp.Body)
	return ioutil.WriteFile(destPath, data, 0644)
}

func generateConfig(destPath string) error {
	config := `{
  "api_base": "http://localhost:8090/api/agents",
  "ws_url": "ws://localhost:8090/ws/",
  "token": "TOKEN_USUARIO",
  "heartbeat_interval": 5,
  "agent_version": "2.0.0",
  "log_level": "info"
}`
	return ioutil.WriteFile(destPath, []byte(config), 0644)
}

func setupService(binaryPath, configPath string) error {
	exec.Command("sc", "delete", ServiceName).Run()
	
	cmd := exec.Command("sc", "create", ServiceName,
		"binPath= \""+binaryPath+"\" --config \""+configPath+"\"",
		"start= auto",
		"DisplayName= \"SecureLab Agent\"")
	return cmd.Run()
}