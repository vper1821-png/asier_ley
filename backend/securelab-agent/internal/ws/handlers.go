package ws

import "encoding/json"

func (c *Client) handleMessage(msgData []byte) {
	var msg map[string]interface{}
	if err := json.Unmarshal(msgData, &msg); err != nil {
		return
	}
	typ, _ := msg["type"].(string)
	switch typ {
	case "ping":
		c.sendChan <- map[string]interface{}{"type": "pong"}
	case "commands":
		// procesar comandos (bloqueos, etc.)
	default:
		c.log.Debug("Mensaje WS no manejado: %s", typ)
	}
}
