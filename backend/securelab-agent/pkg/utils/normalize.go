package utils

import (
	"regexp"
	"strings"
)

func NormalizeQuery(query string) string {
	q := strings.TrimSpace(query)
	q = regexp.MustCompile(`'[^']*'`).ReplaceAllString(q, "'?'")
	q = regexp.MustCompile(`"[^"]*"`).ReplaceAllString(q, "\"?\"")
	q = regexp.MustCompile(`\b\d+\b`).ReplaceAllString(q, "?")
	q = regexp.MustCompile(`\s+`).ReplaceAllString(q, " ")
	return strings.ToUpper(q)
}
