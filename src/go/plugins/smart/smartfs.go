/*
** Zabbix
** Copyright (C) 2001-2020 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/

package smart

import (
	"encoding/json"
	"errors"
	"fmt"
	"runtime"
	"strconv"
	"strings"
	"sync"
	"time"

	"zabbix.com/pkg/zbxerr"
)

const supportedSmartctl = 7.1

var (
	cpuCount     int
	lastVerCheck time.Time
	versionMux   sync.Mutex
)

type devices struct {
	Info []deviceInfo `json:"devices"`
}

type device struct {
	Name         string `json:"{#NAME}"`
	DeviceType   string `json:"{#DISKTYPE}"`
	Model        string `json:"{#MODEL}"`
	SerialNumber string `json:"{#SN}"`
}

type attribute struct {
	Name       string `json:"{#NAME}"`
	DeviceType string `json:"{#DISKTYPE}"`
	ID         int    `json:"{#ID}"`
	Attrname   string `json:"{#ATTRNAME}"`
	Thresh     int    `json:"{#THRESH}"`
}

type deviceParser struct {
	ModelName       string          `json:"model_name"`
	SerialNumber    string          `json:"serial_number"`
	RotationRate    int             `json:"rotation_rate"`
	Info            deviceInfo      `json:"device"`
	Smartctl        smartctlField   `json:"smartctl"`
	SmartStatus     *smartStatus    `json:"smart_status,omitempty"`
	SmartAttributes smartAttributes `json:"ata_smart_attributes"`
}

type deviceInfo struct {
	Name    string `json:"name"`
	DevType string `json:"type"`
}

type smartctl struct {
	Smartctl smartctlField `json:"smartctl"`
}

type smartctlField struct {
	Messages   []message `json:"messages"`
	ExitStatus int       `json:"exit_status"`
	Version    []int     `json:"version"`
}

type message struct {
	Str string `json:"string"`
}

type smartStatus struct {
	SerialNumber bool `json:"passed"`
}

type smartAttributes struct {
	Table []table `json:"table"`
}

type table struct {
	Attrname string `json:"name"`
	ID       int    `json:"id"`
	Thresh   int    `json:"thresh"`
}

type raidParameters struct {
	name  string
	rType string
}

type runner struct {
	plugin         *Plugin
	mux            sync.Mutex
	wg             sync.WaitGroup
	names          chan string
	err            chan error
	done           chan struct{}
	raidDone       chan struct{}
	raids          chan raidParameters
	devices        []deviceParser
	jsonDevices    map[string]string
	found          map[string]bool
	incompleteData []string
}

// execute returns the smartctl runner with all devices data returned by smartctl.
// If jsonRunner is 'true' the returned data is in json format in 'jsonDevices' field.
// If jsonRunner is 'false' the returned data is 'devices' field.
// Currently looks for 5 raid types "3ware", "areca", "cciss", "megaraid", "sat".
// It returns an error if there is an issue with getting or parsing results from smartctl.
func (p *Plugin) execute(jsonRunner bool) (*runner, error) {
	basicDev, raidDev, err := p.getDevices()
	if err != nil {
		return nil, err
	}

	r := &runner{
		names:    make(chan string, len(basicDev)),
		err:      make(chan error, cpuCount),
		done:     make(chan struct{}),
		raidDone: make(chan struct{}),
		plugin:   p,
		found:    make(map[string]bool),
	}

	if jsonRunner {
		r.jsonDevices = make(map[string]string)
	}

	r.startBasicRunners(jsonRunner)

	for _, dev := range basicDev {
		r.names <- dev.Name
	}

	close(r.names)

	err = r.waitForExecution()
	if err != nil {
		return nil, err
	}

	for _, dev := range r.incompleteData {
		raidDev = append(raidDev, deviceInfo{Name: dev})
	}

	r.raids = make(chan raidParameters, len(raidDev)*5)

	r.startRaidRunners(jsonRunner)

	raidTypes := []string{"3ware", "areca", "cciss", "megaraid", "sat"}

	for _, rDev := range raidDev {
		for _, rType := range raidTypes {
			r.raids <- raidParameters{rDev.Name, rType}
		}
	}

	close(r.raids)

	r.waitForRaidExecution()

	return r, err
}

// startBasicRunners starts runners to get basic device information.
// Runner count is based on cpu core count.
func (r *runner) startBasicRunners(jsonRunner bool) {
	r.wg.Add(cpuCount)

	for i := 0; i < cpuCount; i++ {
		go r.getBasicDevices(jsonRunner)
	}
}

// startRaidRunners starts runners to get raid device information.
// Runner count is based on cpu core count.
func (r *runner) startRaidRunners(jsonRunner bool) {
	r.wg.Add(cpuCount)

	for i := 0; i < cpuCount; i++ {
		go r.getRaidDevices(jsonRunner)
	}
}

// waitForExecution waits for all execution to stop.
// Returns the first error a runner sends.
func (r *runner) waitForExecution() error {
	go func() {
		r.wg.Wait()

		close(r.done)
	}()

	select {
	case <-r.done:
		return nil
	case err := <-r.err:
		return err
	}
}

// waitForRaidExecution waits for all execution to stop.
// Returns the first error a runner sends.
func (r *runner) waitForRaidExecution() {
	go func() {
		r.wg.Wait()

		close(r.raidDone)
	}()

	<-r.raidDone
}

// checkVersion checks the version of smartctl.
// Currently supported versions are 7.1 and above.
// It returns an error if there is an issue with getting or parsing results from smartctl.
func (p *Plugin) checkVersion() error {
	var smartctl smartctl

	if !versionCheckNeeded() {
		return nil
	}

	info, err := p.executeSmartctl("-j -V", true)
	if err != nil {
		return fmt.Errorf("Failed to execute smartctl: %s.", err.Error())
	}

	if err = json.Unmarshal(info, &smartctl); err != nil {
		return zbxerr.ErrorCannotUnmarshalJSON.Wrap(err)
	}

	return evaluateVersion(smartctl.Smartctl.Version)
}

// versionCheckNeeded returns true if version needs to be checked.
// Version is checked every 24 hours
func versionCheckNeeded() bool {
	versionMux.Lock()
	defer versionMux.Unlock()

	if lastVerCheck.IsZero() || time.Now().After(lastVerCheck.Add(24*time.Hour)) {
		lastVerCheck = time.Now()

		return true
	}

	return false
}

// evaluateVersion checks version digits if they match the current allowed version or higher.
func evaluateVersion(versionDigits []int) error {
	if len(versionDigits) < 1 {
		return fmt.Errorf("Invalid smartctl version")
	}

	var version string
	if len(versionDigits) >= 2 {
		version = fmt.Sprintf("%d.%d", versionDigits[0], versionDigits[1])
	} else {
		version = fmt.Sprintf("%d", versionDigits[0])
	}

	v, err := strconv.ParseFloat(version, 64)
	if err != nil {
		return zbxerr.ErrorCannotParseResult.Wrap(err)
	}

	if v < supportedSmartctl {
		return fmt.Errorf("Incorrect smartctl version, must be %v or higher", supportedSmartctl)
	}

	return nil
}

// cutPrefix cuts /dev/ prefix from a string and returns it.
func cutPrefix(in string) string {
	return strings.TrimPrefix(in, "/dev/")
}

// getBasicDevices sets non raid device information returned by smartctl.
// Sets device data to runner 'devices' field.
// If jsonRunner is true, sets raw json outputs to runner 'jsonDevices' map instead.
// It sends an error if there is an issue with getting or parsing results from smartctl.
func (r *runner) getBasicDevices(jsonRunner bool) {
	defer r.wg.Done()

	for name := range r.names {
		devices, err := r.plugin.executeSmartctl(fmt.Sprintf("-a %s -j", name), false)
		if err != nil {
			r.err <- fmt.Errorf("Failed to execute smartctl: %s.", err.Error())
			return
		}

		var dp deviceParser

		if err = json.Unmarshal(devices, &dp); err != nil {
			r.err <- zbxerr.ErrorCannotUnmarshalJSON.Wrap(err)
			return
		}

		if err = dp.checkErr(); err != nil {
			r.err <- fmt.Errorf("Smartctl failed to get device data: %s.", err.Error())
			return
		}

		if dp.SmartStatus != nil {
			r.mux.Lock()
			if !r.found[dp.SerialNumber] {
				r.found[dp.SerialNumber] = true

				if dp.Smartctl.ExitStatus == 4 {
					r.incompleteData = append(r.incompleteData, name)
					continue
				}

				if jsonRunner {
					r.jsonDevices[name] = string(devices)
				} else {
					r.devices = append(r.devices, dp)
				}
			}
			r.mux.Unlock()
		}
	}
}

// getRaidDevices sets raid device information returned by smartctl.
// Works by incrementing raid disk number till there is an error from smartctl.
// Sets device data to runner 'devices' field.
// If jsonRunner is true, sets raw json outputs to runner 'jsonDevices' map instead.
// It logs an error when there is an issue with getting or parsing results from smartctl.
func (r *runner) getRaidDevices(jsonRunner bool) {
	defer r.wg.Done()

	var i int

	for raid := range r.raids {
		if i == 0 && raid.rType == "areca" {
			i = 1
		}

		var name string

		if raid.rType == "sat" {
			name = fmt.Sprintf("%s -d %s", raid.name, raid.rType)
		} else {
			name = fmt.Sprintf("%s -d %s,%d", raid.name, raid.rType, i)
		}

		device, err := r.plugin.executeSmartctl(fmt.Sprintf("-a %s -j ", name), false)
		if err != nil {
			r.plugin.Tracef(
				"stopped looking for RAID devices of %s type, err:",
				raid.rType, fmt.Errorf("failed to get RAID disk data from smartctl: %s", err.Error()),
			)

			return
		}

		var dp deviceParser
		if err = json.Unmarshal(device, &dp); err != nil {
			r.plugin.Tracef(
				"stopped looking for RAID devices of %s type, err:",
				raid.rType, fmt.Errorf("failed to get RAID disk data from smartctl: %s", err.Error()),
			)

			return
		}

		err = dp.checkErr()
		if err != nil {
			r.plugin.Tracef(
				"stopped looking for RAID devices of %s type, err:",
				raid.rType, fmt.Errorf("failed to get disk data from smartctl: %s", err.Error()),
			)

			return
		}

		if dp.SmartStatus != nil {
			dp.Info.Name = fmt.Sprintf("%s %s,%d", raid.name, raid.rType, i)

			r.mux.Lock()

			if jsonRunner {
				r.jsonDevices[dp.Info.Name] = string(device)
			} else {
				r.devices = append(r.devices, dp)
			}

			if raid.rType == "sat" {
				return
			}

			r.mux.Unlock()
		}

		i++
	}
}

func (dp *deviceParser) checkErr() (err error) {
	if dp.Smartctl.ExitStatus != 2 {
		return
	}

	for _, m := range dp.Smartctl.Messages {
		if err == nil {
			err = errors.New(m.Str)
			continue
		}

		err = fmt.Errorf("%s, %s", err.Error(), m.Str)
	}

	if err == nil {
		err = errors.New("unknown error from smartctl")
	}

	return
}

// getDevices returns a parsed slices of all devices returned by smartctl scan.
// Returns a separate slice for both normal and raid devices.
// It returns an error if there is an issue with getting or parsing results from smartctl.
func (p *Plugin) getDevices() (basic, raid []deviceInfo, err error) {
	basic, err = p.scanDevices("--scan -j")
	if err != nil {
		return nil, nil, fmt.Errorf("Failed to scan for devices: %s.", err)
	}

	raidTmp, err := p.scanDevices("--scan -d sat -j")
	if err != nil {
		return nil, nil, fmt.Errorf("Failed to scan for sat devices: %s.", err)
	}

raid:
	for _, tmp := range raidTmp {
		for _, b := range basic {
			if tmp.Name == b.Name {
				continue raid
			}
		}

		raid = append(raid, tmp)
	}

	return basic, raid, nil
}

// scanDevices executes smartctl.
// It parses the smartctl data into a slice with deviceInfo.
// It returns an error if there is an issue with getting or parsing results from smartctl.
func (p *Plugin) scanDevices(args string) ([]deviceInfo, error) {
	var d devices

	devices, err := p.executeSmartctl(args, false)
	if err != nil {
		return nil, err
	}

	if err = json.Unmarshal(devices, &d); err != nil {
		return nil, zbxerr.ErrorCannotUnmarshalJSON.Wrap(err)
	}

	return d.Info, nil
}

func init() {
	cpuCount = runtime.NumCPU()
	if cpuCount < 1 {
		cpuCount = 1
	}
}
