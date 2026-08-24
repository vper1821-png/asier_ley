//go:build !windows

package main

import (
	"context"

	"securelab-agent/platform/unix"
)

func runPlatformService(runFunc func(context.Context)) error {
	unix.RunService(runFunc)
	return nil
}

func installPlatformService() error {
	return unix.InstallService()
}

func removePlatformService() error {
	return unix.RemoveService()
}
