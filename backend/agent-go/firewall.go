package main

import (
	"fmt"
	"os/exec"
	"runtime"
	"strings"
)

type FWApplyResult struct {
	Rule    string `json:"rule"`
	Success bool   `json:"success"`
	Method  string `json:"method"`
	Error   string `json:"error,omitempty"`
}

func applyFWRules(rules []Rule) []FWApplyResult {
	var results []FWApplyResult
	for _, rule := range rules {
		r := applyFWRule(rule)
		results = append(results, r)
	}
	return results
}

func applyFWRule(rule Rule) FWApplyResult {
	name := fmt.Sprintf("Invisia_%s", sanitizeName(ruleName(rule)))
	switch runtime.GOOS {
	case "windows":
		return applyFWWindows(name, rule)
	case "linux":
		return applyFWIptables(name, rule)
	case "darwin":
		return applyFWPFctl(name, rule)
	}
	return FWApplyResult{Rule: name, Error: "unsupported_platform"}
}

func ruleName(r Rule) string {
	if r.Name != "" {
		return r.Name
	}
	return r.ID
}

func sanitizeName(s string) string {
	var out strings.Builder
	for _, c := range s {
		if (c >= 'a' && c <= 'z') || (c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9') || c == '_' || c == '-' {
			out.WriteRune(c)
		} else {
			out.WriteRune('_')
		}
	}
	return out.String()
}

func applyFWWindows(name string, rule Rule) FWApplyResult {
	dir := "any"
	switch rule.Type {
	case "inbound":
		dir = "in"
	case "outbound":
		dir = "out"
	}
	action := rule.Action
	if action == "" {
		action = "deny"
	}
	proto := rule.Protocol
	if proto == "" {
		proto = "any"
	}
	args := []string{"advfirewall", "firewall", "add", "rule",
		fmt.Sprintf("name=%s", name),
		fmt.Sprintf("dir=%s", dir),
		fmt.Sprintf("action=%s", action),
		fmt.Sprintf("protocol=%s", proto),
	}
	if rule.Port != "" {
		args = append(args, fmt.Sprintf("localport=%s", rule.Port))
	}
	if rule.SourceIP != "" {
		args = append(args, fmt.Sprintf("remoteip=%s", rule.SourceIP))
	}
	err := exec.Command("netsh", args...).Run()
	if err != nil {
		return FWApplyResult{Rule: name, Error: err.Error()}
	}
	return FWApplyResult{Rule: name, Success: true, Method: "windows_firewall"}
}

func applyFWIptables(name string, rule Rule) FWApplyResult {
	action := "DROP"
	if rule.Action == "allow" {
		action = "ACCEPT"
	}
	chain := "INPUT"
	if rule.Type == "outbound" {
		chain = "OUTPUT"
	}
	args := []string{"iptables", "-A", chain}
	if rule.Protocol != "" && rule.Protocol != "any" {
		args = append(args, "-p", rule.Protocol)
	}
	if rule.SourceIP != "" {
		args = append(args, "-s", rule.SourceIP)
	}
	if rule.Port != "" {
		args = append(args, "--dport", rule.Port)
	}
	args = append(args, "-j", action)
	err := exec.Command("sudo", args...).Run()
	if err != nil {
		return FWApplyResult{Rule: name, Error: err.Error()}
	}
	return FWApplyResult{Rule: name, Success: true, Method: "iptables"}
}

func applyFWPFctl(name string, rule Rule) FWApplyResult {
	action := "block"
	if rule.Action == "allow" {
		action = "pass"
	}
	dir := "in"
	if rule.Type == "outbound" {
		dir = "out"
	}
	ruleStr := fmt.Sprintf("%s %s", action, dir)
	if rule.Protocol != "" && rule.Protocol != "any" {
		ruleStr += " proto " + rule.Protocol
	}
	if rule.SourceIP != "" {
		ruleStr += " from " + rule.SourceIP
	} else {
		ruleStr += " from any"
	}
	if rule.Port != "" {
		ruleStr += " port " + rule.Port
	}
	cmd := fmt.Sprintf("echo \"%s\" | sudo pfctl -a \"%s\" -f - 2>/dev/null || true", ruleStr, name)
	err := exec.Command("sh", "-c", cmd).Run()
	if err != nil {
		return FWApplyResult{Rule: name, Error: err.Error()}
	}
	return FWApplyResult{Rule: name, Success: true, Method: "pfctl"}
}

func removeFWRule(ruleName string) FWApplyResult {
	name := fmt.Sprintf("Invisia_%s", sanitizeName(ruleName))
	switch runtime.GOOS {
	case "windows":
		err := exec.Command("netsh", "advfirewall", "firewall", "delete", "rule", fmt.Sprintf("name=%s", name)).Run()
		if err != nil {
			return FWApplyResult{Rule: name, Error: err.Error()}
		}
	case "linux":
		exec.Command("sh", "-c", fmt.Sprintf("sudo iptables -D INPUT -m comment --comment \"%s\" 2>/dev/null || sudo iptables -D OUTPUT -m comment --comment \"%s\" 2>/dev/null || true", name, name)).Run()
	case "darwin":
		exec.Command("sudo", "pfctl", "-a", name, "-F", "all").Run()
	}
	return FWApplyResult{Rule: name, Success: true}
}

func getActiveRuleNames() []string {
	switch runtime.GOOS {
	case "windows":
		out, err := exec.Command("netsh", "advfirewall", "firewall", "show", "rule", "name=all", "dir=in").Output()
		if err != nil {
			return nil
		}
		var rules []string
		for _, line := range strings.Split(string(out), "\n") {
			if strings.Contains(line, "Invisia_") {
				parts := strings.SplitN(line, ":", 2)
				if len(parts) == 2 {
					rules = append(rules, strings.TrimSpace(parts[1]))
				}
			}
		}
		return rules
	case "linux":
		out, err := exec.Command("sudo", "iptables", "-L", "INPUT", "-n").Output()
		if err != nil {
			return nil
		}
		var rules []string
		for _, line := range strings.Split(string(out), "\n") {
			if strings.Contains(line, "Invisia_") {
				rules = append(rules, strings.TrimSpace(line))
			}
		}
		return rules
	case "darwin":
		out, err := exec.Command("sudo", "pfctl", "-s", "Anchors").Output()
		if err != nil {
			return nil
		}
		var rules []string
		for _, line := range strings.Split(string(out), "\n") {
			if strings.Contains(line, "Invisia_") {
				rules = append(rules, strings.TrimSpace(strings.Replace(line, "Invisia_", "", 1)))
			}
		}
		return rules
	}
	return nil
}
