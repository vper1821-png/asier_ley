package main

import (
	"securelab-agent/internal/config"
	"securelab-agent/internal/logger"
)

var persistenceInstaller func(cfg *config.Config, log *logger.Logger)