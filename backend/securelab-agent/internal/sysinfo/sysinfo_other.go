//go:build !windows

package sysinfo

func GetProcesses() []Process {
	return []Process{}
}

func GetHealth() Health {
	return Health{Hostname: hostname(), Platform: platform(), TopProcesses: []Process{}}
}

func GetDefender() Defender {
	return Defender{}
}

func CaptureScreenshot() (string, error) {
	return "", nil
}
