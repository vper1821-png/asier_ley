//go:build windows

package telemetry

import (
	"os"
	"runtime"
	"sync"
	"syscall"
	"time"
	"unsafe"

	"securelab-agent/internal/models"
	"golang.org/x/sys/windows"
)

var (
	modKernel32 = windows.NewLazySystemDLL("kernel32.dll")
	modPsapi    = windows.NewLazySystemDLL("psapi.dll")

	procGlobalMemoryStatusEx   = modKernel32.NewProc("GlobalMemoryStatusEx")
	procGetDiskFreeSpaceExW    = modKernel32.NewProc("GetDiskFreeSpaceExW")
	procGetSystemTimes         = modKernel32.NewProc("GetSystemTimes")
	procGetTickCount64         = modKernel32.NewProc("GetTickCount64")
	procK32EnumProcesses       = modKernel32.NewProc("K32EnumProcesses")
	procEnumProcesses          = modPsapi.NewProc("EnumProcesses")

	cpuMutex       sync.Mutex
	lastIdleTime   uint64
	lastKernelTime uint64
	lastUserTime   uint64
	lastCPUPct     int
	hasFirstSample bool
)

type memorystatusex struct {
	Length               uint32
	MemoryLoad           uint32
	TotalPhys            uint64
	AvailPhys            uint64
	TotalPageFile        uint64
	AvailPageFile        uint64
	TotalVirtual         uint64
	AvailVirtual         uint64
	AvailExtendedVirtual uint64
}

func collect() models.TelemetryData {
	host, _ := os.Hostname()
	data := models.TelemetryData{
		Timestamp:   time.Now().Unix(),
		Hostname:    host,
		Platform:    runtime.GOOS,
		Arch:        runtime.GOARCH,
		Connections: 0,
	}

	data.CPU = getCPUUsage()
	data.Memory = getMemoryUsage()
	data.DiskTotal, data.DiskFree = getDiskUsage()
	data.Uptime = getUptimeSeconds()
	data.Processes = getProcessCount()

	return data
}

func getCPUUsage() int {
	cpuMutex.Lock()
	defer cpuMutex.Unlock()

	if procGetSystemTimes.Find() != nil {
		return lastCPUPct
	}

	var idleTime, kernelTime, userTime windows.Filetime
	r, _, _ := procGetSystemTimes.Call(
		uintptr(unsafe.Pointer(&idleTime)),
		uintptr(unsafe.Pointer(&kernelTime)),
		uintptr(unsafe.Pointer(&userTime)),
	)
	if r == 0 {
		return lastCPUPct
	}

	curIdle := (uint64(idleTime.HighDateTime) << 32) | uint64(idleTime.LowDateTime)
	curKernel := (uint64(kernelTime.HighDateTime) << 32) | uint64(kernelTime.LowDateTime)
	curUser := (uint64(userTime.HighDateTime) << 32) | uint64(userTime.LowDateTime)

	if !hasFirstSample {
		lastIdleTime = curIdle
		lastKernelTime = curKernel
		lastUserTime = curUser
		hasFirstSample = true
		return 5 // initial baseline
	}

	idleDiff := curIdle - lastIdleTime
	kernelDiff := curKernel - lastKernelTime
	userDiff := curUser - lastUserTime

	lastIdleTime = curIdle
	lastKernelTime = curKernel
	lastUserTime = curUser

	total := kernelDiff + userDiff
	if total == 0 {
		return lastCPUPct
	}

	if idleDiff > total {
		idleDiff = total
	}

	busy := total - idleDiff
	pct := int((busy * 100) / total)
	if pct < 0 {
		pct = 0
	}
	if pct > 100 {
		pct = 100
	}

	lastCPUPct = pct
	return pct
}

func getMemoryUsage() int {
	if procGlobalMemoryStatusEx.Find() != nil {
		return 0
	}

	var mem memorystatusex
	mem.Length = uint32(unsafe.Sizeof(mem))
	r, _, _ := procGlobalMemoryStatusEx.Call(uintptr(unsafe.Pointer(&mem)))
	if r == 0 {
		return 0
	}

	if mem.MemoryLoad > 100 {
		return 100
	}
	return int(mem.MemoryLoad)
}

func getDiskUsage() (int64, int64) {
	if procGetDiskFreeSpaceExW.Find() != nil {
		return 0, 0
	}

	// Try system drive (e.g. C:\)
	rootPath := "C:\\"
	if sysDrive := os.Getenv("SystemDrive"); sysDrive != "" {
		rootPath = sysDrive + "\\"
	}

	pathPtr, err := syscall.UTF16PtrFromString(rootPath)
	if err != nil {
		return 0, 0
	}

	var freeBytesAvailable, totalNumberOfBytes, totalNumberOfFreeBytes uint64
	r, _, _ := procGetDiskFreeSpaceExW.Call(
		uintptr(unsafe.Pointer(pathPtr)),
		uintptr(unsafe.Pointer(&freeBytesAvailable)),
		uintptr(unsafe.Pointer(&totalNumberOfBytes)),
		uintptr(unsafe.Pointer(&totalNumberOfFreeBytes)),
	)
	if r == 0 {
		return 0, 0
	}

	return int64(totalNumberOfBytes), int64(freeBytesAvailable)
}

func getUptimeSeconds() int64 {
	if procGetTickCount64.Find() == nil {
		r, _, _ := procGetTickCount64.Call()
		if r > 0 {
			return int64(r / 1000)
		}
	}
	return 0
}

func getProcessCount() int {
	var pids [4096]uint32
	var bytesReturned uint32

	if procK32EnumProcesses.Find() == nil {
		r, _, _ := procK32EnumProcesses.Call(
			uintptr(unsafe.Pointer(&pids[0])),
			uintptr(sizeof(pids)),
			uintptr(unsafe.Pointer(&bytesReturned)),
		)
		if r != 0 && bytesReturned > 0 {
			return int(bytesReturned / 4)
		}
	}

	if procEnumProcesses.Find() == nil {
		r, _, _ := procEnumProcesses.Call(
			uintptr(unsafe.Pointer(&pids[0])),
			uintptr(sizeof(pids)),
			uintptr(unsafe.Pointer(&bytesReturned)),
		)
		if r != 0 && bytesReturned > 0 {
			return int(bytesReturned / 4)
		}
	}

	return 0
}

func sizeof(arr [4096]uint32) uint32 {
	return uint32(len(arr) * 4)
}
