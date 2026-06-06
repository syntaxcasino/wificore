package ssh

import (
	"bufio"
	"crypto/md5"
	"encoding/hex"
	"errors"
	"fmt"
	"io"
	"net"
	"sort"
	"strconv"
	"strings"
	"time"
)

var errUnsupportedCommand = errors.New("unsupported command for binary api")

// binaryAPIClient speaks the RouterOS binary API directly.
type binaryAPIClient struct {
	host     string
	ports    []int
	username string
	password string
	timeout  time.Duration

	conn   net.Conn
	reader *bufio.Reader
}

func newBinaryAPIClient(host string, ports []int, username, password string, timeout time.Duration) *binaryAPIClient {
	return &binaryAPIClient{
		host:     host,
		ports:    ports,
		username: username,
		password: password,
		timeout:  timeout,
	}
}

func (c *binaryAPIClient) connect() error {
	var lastErr error
	for _, port := range c.ports {
		if port <= 0 {
			continue
		}
		if err := c.connectPort(port); err != nil {
			lastErr = err
			continue
		}
		if err := c.login(); err != nil {
			lastErr = err
			_ = c.close()
			continue
		}
		return nil
	}

	if lastErr == nil {
		lastErr = fmt.Errorf("no binary api port reachable for %s", c.host)
	}
	return lastErr
}

func (c *binaryAPIClient) connectPort(port int) error {
	addr := fmt.Sprintf("%s:%d", c.host, port)
	conn, err := net.DialTimeout("tcp", addr, c.timeout)
	if err != nil {
		return fmt.Errorf("binary api connection failed to %s: %w", addr, err)
	}

	_ = conn.SetDeadline(time.Now().Add(c.timeout))
	c.conn = conn
	c.reader = bufio.NewReader(conn)
	return nil
}

func (c *binaryAPIClient) close() error {
	if c.conn != nil {
		err := c.conn.Close()
		c.conn = nil
		c.reader = nil
		return err
	}
	return nil
}

func (c *binaryAPIClient) login() error {
	// Try the newer one-step login first. Some RouterOS versions accept this directly.
	if _, err := c.command("/login", []string{"name=" + c.username, "password=" + c.password}); err == nil {
		return nil
	}

	// Fall back to the challenge/response flow used by older RouterOS versions.
	challengeResp, err := c.command("/login", nil)
	if err != nil {
		return err
	}

	challenge := ""
	if len(challengeResp) > 0 {
		challenge = challengeResp[len(challengeResp)-1]["ret"]
	}
	if strings.TrimSpace(challenge) == "" {
		return fmt.Errorf("binary api login failed: no challenge returned")
	}

	challengeBytes, err := hex.DecodeString(challenge)
	if err != nil {
		return fmt.Errorf("binary api login failed: invalid challenge: %w", err)
	}

	sum := md5.Sum(append([]byte{0}, append([]byte(c.password), challengeBytes...)...))
	_, err = c.command("/login", []string{"name=" + c.username, "response=00" + hex.EncodeToString(sum[:])})
	return err
}

func (c *binaryAPIClient) command(endpoint string, params []string) ([]map[string]string, error) {
	if c.conn == nil || c.reader == nil {
		return nil, fmt.Errorf("binary api client is not connected")
	}

	words := []string{endpoint}
	for _, param := range params {
		if strings.TrimSpace(param) == "" {
			continue
		}
		if strings.HasPrefix(param, "?") || strings.HasPrefix(param, "=") {
			words = append(words, param)
			continue
		}
		words = append(words, "="+strings.TrimLeft(param, "="))
	}

	if err := c.writeSentence(words); err != nil {
		return nil, err
	}
	return c.readResponse()
}

func (c *binaryAPIClient) execute(command string) (string, error) {
	endpoint, params, opts, err := translateRouterOSCommand(command)
	if err != nil {
		return "", err
	}

	if opts.scriptDeploy {
		return "", errUnsupportedCommand
	}

	if opts.findMutation {
		return c.executeFindMutation(endpoint, opts.action, opts.findFilters, params)
	}

	records, err := c.command(endpoint, params)
	if err != nil {
		return "", err
	}

	if opts.countOnly {
		return strconv.Itoa(len(records)), nil
	}
	if opts.detailOutput {
		return formatDetailRecords(records), nil
	}
	return formatKeyValueRecords(records), nil
}

func (c *binaryAPIClient) executeScript(script string) (string, error) {
	script = strings.TrimSpace(script)
	if script == "" {
		return "", fmt.Errorf("binary api script is empty")
	}

	name := fmt.Sprintf("wificore-%d", time.Now().UnixNano())
	source := script
	if _, err := c.command("/system/script/add", []string{"name=" + name, "source=" + source}); err != nil {
		return "", err
	}
	defer func() {
		_, _ = c.command("/system/script/remove", []string{"=name=" + name})
	}()

	if _, err := c.command("/system/script/run", []string{"=name=" + name}); err != nil {
		return "", err
	}

	return "script executed", nil
}

func (c *binaryAPIClient) executeFindMutation(baseEndpoint, action string, findFilters []string, extraParams []string) (string, error) {
	searchEndpoint := strings.TrimSuffix(baseEndpoint, "/"+action) + "/print"
	records, err := c.command(searchEndpoint, findFilters)
	if err != nil {
		return "", err
	}

	if len(records) == 0 {
		return "", nil
	}

	outputs := make([]string, 0, len(records))
	for _, record := range records {
		id := record[".id"]
		if strings.TrimSpace(id) == "" {
			continue
		}

		params := append([]string{}, extraParams...)
		params = append(params, ".id="+id)
		result, err := c.command(strings.TrimSuffix(baseEndpoint, "/"+action)+"/"+action, params)
		if err != nil {
			return "", err
		}
		outputs = append(outputs, formatKeyValueRecords(result))
	}

	return strings.TrimSpace(strings.Join(outputs, "\n")), nil
}

func (c *binaryAPIClient) writeSentence(words []string) error {
	data := make([]byte, 0, 64)
	for _, word := range words {
		data = append(data, encodeLength(len(word))...)
		data = append(data, []byte(word)...)
	}
	data = append(data, 0)

	written, err := c.conn.Write(data)
	if err != nil {
		return fmt.Errorf("binary api socket write failed: %w", err)
	}
	if written != len(data) {
		return fmt.Errorf("binary api socket write truncated: wrote %d of %d bytes", written, len(data))
	}
	return nil
}

func (c *binaryAPIClient) readResponse() ([]map[string]string, error) {
	records := make([]map[string]string, 0)

	for {
		sentence, err := c.readSentence()
		if err != nil {
			return nil, err
		}
		if len(sentence) == 0 {
			continue
		}

		typ := sentence[0]
		switch typ {
		case "!re":
			record := map[string]string{}
			for _, word := range sentence[1:] {
				if strings.HasPrefix(word, "=") {
					word = strings.TrimPrefix(word, "=")
					parts := strings.SplitN(word, "=", 2)
					if len(parts) == 2 {
						record[parts[0]] = parts[1]
					}
				}
			}
			records = append(records, record)
		case "!done", "!empty":
			doneRecord := map[string]string{}
			for _, word := range sentence[1:] {
				if strings.HasPrefix(word, "=") {
					word = strings.TrimPrefix(word, "=")
					parts := strings.SplitN(word, "=", 2)
					if len(parts) == 2 {
						doneRecord[parts[0]] = parts[1]
					}
				}
			}
			if len(doneRecord) > 0 {
				records = append(records, doneRecord)
			}
			return records, nil
		case "!trap", "!fatal":
			msg := ""
			for _, word := range sentence[1:] {
				if strings.HasPrefix(word, "=message=") {
					msg = strings.TrimPrefix(word, "=message=")
					break
				}
			}
			if msg == "" {
				msg = "routeros api error"
			}
			return nil, fmt.Errorf("RouterOS API error (%s): %s", typ, msg)
		default:
			// keep reading until !done / !trap.
		}
	}
}

func (c *binaryAPIClient) readSentence() ([]string, error) {
	words := make([]string, 0, 8)
	for {
		length, err := c.decodeLength()
		if err != nil {
			return nil, err
		}
		if length == 0 {
			return words, nil
		}
		chunk := make([]byte, length)
		if _, err := io.ReadFull(c.reader, chunk); err != nil {
			return nil, fmt.Errorf("binary api socket closed unexpectedly while reading: %w", err)
		}
		words = append(words, string(chunk))
	}
}

func (c *binaryAPIClient) decodeLength() (int, error) {
	first, err := c.reader.ReadByte()
	if err != nil {
		return 0, fmt.Errorf("binary api socket closed unexpectedly while reading length: %w", err)
	}

	switch {
	case first&0x80 == 0x00:
		return int(first), nil
	case first&0xC0 == 0x80:
		second, err := c.reader.ReadByte()
		if err != nil {
			return 0, err
		}
		return (int(first&0x3F) << 8) | int(second), nil
	case first&0xE0 == 0xC0:
		raw := make([]byte, 2)
		if _, err := io.ReadFull(c.reader, raw); err != nil {
			return 0, err
		}
		return (int(first&0x1F) << 16) | (int(raw[0]) << 8) | int(raw[1]), nil
	case first&0xF0 == 0xE0:
		raw := make([]byte, 3)
		if _, err := io.ReadFull(c.reader, raw); err != nil {
			return 0, err
		}
		return (int(first&0x0F) << 24) | (int(raw[0]) << 16) | (int(raw[1]) << 8) | int(raw[2]), nil
	default:
		raw := make([]byte, 4)
		if _, err := io.ReadFull(c.reader, raw); err != nil {
			return 0, err
		}
		return int(raw[0])<<24 | int(raw[1])<<16 | int(raw[2])<<8 | int(raw[3]), nil
	}
}

func encodeLength(length int) []byte {
	switch {
	case length < 0x80:
		return []byte{byte(length)}
	case length < 0x4000:
		length |= 0x8000
		return []byte{byte(length >> 8), byte(length)}
	case length < 0x200000:
		length |= 0xC00000
		return []byte{byte(length >> 16), byte(length >> 8), byte(length)}
	case length < 0x10000000:
		length |= 0xE0000000
		return []byte{byte(length >> 24), byte(length >> 16), byte(length >> 8), byte(length)}
	default:
		return []byte{0xF0, byte(length >> 24), byte(length >> 16), byte(length >> 8), byte(length)}
	}
}

type commandOptions struct {
	action       string
	countOnly    bool
	detailOutput bool
	scriptDeploy bool
	findMutation bool
	findFilters  []string
}

func translateRouterOSCommand(command string) (string, []string, commandOptions, error) {
	tokens := splitRouterOSCommand(command)
	if len(tokens) == 0 {
		return "", nil, commandOptions{}, errUnsupportedCommand
	}

	actionIndex := -1
	for i, token := range tokens {
		if isActionToken(strings.ToLower(token)) {
			actionIndex = i
			break
		}
	}
	if actionIndex == -1 {
		return "", nil, commandOptions{}, errUnsupportedCommand
	}

	pathParts := make([]string, 0, actionIndex)
	for _, token := range tokens[:actionIndex] {
		part := strings.Trim(token, "/")
		if part == "" {
			continue
		}
		pathParts = append(pathParts, part)
	}

	endpoint := "/" + strings.Join(pathParts, "/") + "/" + strings.ToLower(tokens[actionIndex])
	if len(pathParts) == 0 {
		return "", nil, commandOptions{}, errUnsupportedCommand
	}

	opts := commandOptions{action: strings.ToLower(tokens[actionIndex])}
	rest := tokens[actionIndex+1:]
	params := make([]string, 0, len(rest))

	for i := 0; i < len(rest); i++ {
		tok := rest[i]
		low := strings.ToLower(tok)
		switch low {
		case "detail":
			opts.detailOutput = true
			continue
		case "without-paging":
			continue
		case "count-only":
			opts.countOnly = true
			continue
		case "where":
			for _, filter := range rest[i+1:] {
				if strings.Contains(filter, "=") {
					params = append(params, "?"+stripAngleAndQuotes(filter))
				}
			}
			i = len(rest)
			continue
		}

		if strings.HasPrefix(tok, "[find") || strings.Contains(tok, "[find") {
			opts.findMutation = true
			filters := extractFindFilters(strings.Join(rest[i:], " "))
			if len(filters) == 0 {
				return "", nil, commandOptions{}, errUnsupportedCommand
			}
			opts.findFilters = filters
			break
		}

		if strings.Contains(tok, "=") {
			params = append(params, stripAngleAndQuotes(tok))
			continue
		}

		if opts.action == "set" || opts.action == "remove" || opts.action == "enable" || opts.action == "disable" {
			params = append(params, "numbers="+stripAngleAndQuotes(tok))
			continue
		}

		params = append(params, stripAngleAndQuotes(tok))
	}

	if strings.Contains(strings.ToLower(command), "/system script add") {
		opts.scriptDeploy = true
	}

	return endpoint, params, opts, nil
}

func splitRouterOSCommand(command string) []string {
	tokens := make([]string, 0, 16)
	var current strings.Builder
	inQuotes := false
	bracketDepth := 0
	escaped := false

	flush := func() {
		if current.Len() > 0 {
			tokens = append(tokens, current.String())
			current.Reset()
		}
	}

	for _, r := range command {
		switch {
		case escaped:
			current.WriteRune(r)
			escaped = false
		case r == '\\':
			current.WriteRune(r)
			escaped = true
		case r == '"':
			current.WriteRune(r)
			inQuotes = !inQuotes
		case r == '[' && !inQuotes:
			current.WriteRune(r)
			bracketDepth++
		case r == ']' && !inQuotes && bracketDepth > 0:
			current.WriteRune(r)
			bracketDepth--
		case (r == ' ' || r == '\t') && !inQuotes && bracketDepth == 0:
			flush()
		default:
			current.WriteRune(r)
		}
	}
	flush()
	return tokens
}

func isActionToken(token string) bool {
	switch token {
	case "print", "add", "set", "remove", "run", "enable", "disable", "import":
		return true
	default:
		return false
	}
}

func stripAngleAndQuotes(token string) string {
	return cleanQueryValue(strings.Trim(token, "[]"))
}

func extractFindFilters(command string) []string {
	start := strings.Index(command, "[find")
	if start == -1 {
		return nil
	}
	end := strings.Index(command[start:], "]")
	if end == -1 {
		return nil
	}
	inner := strings.TrimSpace(command[start+len("[find") : start+end])
	if inner == "" {
		return nil
	}

	filters := make([]string, 0)
	for _, token := range splitRouterOSCommand(inner) {
		if strings.Contains(token, "=") {
			filters = append(filters, "?"+stripAngleAndQuotes(token))
		}
	}
	return filters
}

func formatKeyValueRecords(records []map[string]string) string {
	if len(records) == 0 {
		return ""
	}

	lines := make([]string, 0, len(records)*len(records[0]))
	for _, record := range records {
		keys := make([]string, 0, len(record))
		for key := range record {
			keys = append(keys, key)
		}
		sort.Strings(keys)
		for _, key := range keys {
			lines = append(lines, fmt.Sprintf("%s: %s", key, record[key]))
		}
	}
	return strings.Join(lines, "\n")
}

func formatDetailRecords(records []map[string]string) string {
	if len(records) == 0 {
		return ""
	}

	lines := make([]string, 0, len(records))
	for idx, record := range records {
		keys := make([]string, 0, len(record))
		for key := range record {
			keys = append(keys, key)
		}
		sort.Strings(keys)

		parts := make([]string, 0, len(keys)+1)
		parts = append(parts, strconv.Itoa(idx))
		for _, key := range keys {
			parts = append(parts, fmt.Sprintf("%s=%s", key, quoteIfNeeded(record[key])))
		}
		lines = append(lines, strings.Join(parts, " "))
	}
	return strings.Join(lines, "\n")
}

func quoteIfNeeded(value string) string {
	if value == "" {
		return "\"\""
	}
	if strings.ContainsAny(value, " \t\n\r\"") {
		return fmt.Sprintf("%q", value)
	}
	return value
}

func cleanQueryValue(value string) string {
	value = strings.TrimSpace(value)
	value = strings.TrimPrefix(value, "=")
	value = strings.TrimPrefix(value, "?")
	return strings.Trim(value, "\"")
}
