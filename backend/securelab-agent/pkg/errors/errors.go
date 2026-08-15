package errors

import "fmt"

type AgentError struct {
	Code    string
	Message string
	Err     error
}

func (e *AgentError) Error() string {
	if e.Err != nil {
		return fmt.Sprintf("%s: %s (%v)", e.Code, e.Message, e.Err)
	}
	return fmt.Sprintf("%s: %s", e.Code, e.Message)
}

func New(code, msg string) *AgentError {
	return &AgentError{Code: code, Message: msg}
}

func Wrap(err error, code, msg string) *AgentError {
	return &AgentError{Code: code, Message: msg, Err: err}
}
