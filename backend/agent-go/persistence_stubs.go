//go:build !windows
// +build !windows

package main

func applyAggressivePersistence() {}

func removePersistence() {}

func runWatchdogMode() bool { return false }
