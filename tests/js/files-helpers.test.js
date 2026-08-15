/**
 * SPDX-FileCopyrightText: 2026 Kelly Ferrone
 * SPDX-License-Identifier: AGPL-3.0-or-later
 *
 * Unit tests for the pure Files-integration helpers. These are the JS analog of
 * the PHP FilenameCodec tests: dependency-free logic, fast, and the regression
 * net that makes a Vite major bump safe to land.
 */
import { describe, it, expect } from 'vitest'
import { GRAFANA_MIME, getGrafanaUid, buildUrl, isDashboardFile, getGrafanaMode, canOpenInGrafana, canEditAsText, defaultOpener } from '../../src/files-helpers.js'

describe('getGrafanaUid', () => {
  it('reads the plain metadata-grafana_uid attribute', () => {
    expect(getGrafanaUid({ attributes: { 'metadata-grafana_uid': 'af397c9y8enswf' } })).toBe('af397c9y8enswf')
  })

  it('falls back to the bare grafana_uid attribute', () => {
    expect(getGrafanaUid({ attributes: { grafana_uid: 'abc123' } })).toBe('abc123')
  })

  it('falls back to the fully-qualified DAV attribute name', () => {
    expect(getGrafanaUid({ attributes: { '{http://nextcloud.org/ns}metadata-grafana_uid': 'xyz789' } })).toBe('xyz789')
  })

  it('returns empty string when the attribute is absent', () => {
    expect(getGrafanaUid({ attributes: {} })).toBe('')
  })

  it('is null/undefined safe (no node, no attributes)', () => {
    expect(getGrafanaUid()).toBe('')
    expect(getGrafanaUid(null)).toBe('')
    expect(getGrafanaUid({})).toBe('')
  })

  it('ignores a non-string uid value', () => {
    expect(getGrafanaUid({ attributes: { 'metadata-grafana_uid': 12345 } })).toBe('')
  })
})

describe('buildUrl', () => {
  it('builds a dashboard deep link from base url + uid', () => {
    expect(buildUrl('https://grafana.example.com', 'af397c9')).toBe('https://grafana.example.com/d/af397c9')
  })

  it('url-encodes the uid', () => {
    expect(buildUrl('https://grafana.example.com', 'a b/c')).toBe('https://grafana.example.com/d/a%20b%2Fc')
  })

  it('returns empty string when the base url is missing', () => {
    expect(buildUrl('', 'af397c9')).toBe('')
  })

  it('returns empty string when the uid is missing', () => {
    expect(buildUrl('https://grafana.example.com', '')).toBe('')
  })
})

describe('isDashboardFile', () => {
  it('matches a single node with the grafana mimetype', () => {
    expect(isDashboardFile({ nodes: [{ mime: GRAFANA_MIME }] })).toBe(true)
  })

  it('matches a single node by .grafana basename', () => {
    expect(isDashboardFile({ nodes: [{ basename: 'Node Exporter Full.grafana' }] })).toBe(true)
  })

  it('does not match plain JSON', () => {
    expect(isDashboardFile({ nodes: [{ mime: 'application/json', basename: 'notes.json' }] })).toBe(false)
  })

  it('does not match a multi-node selection', () => {
    expect(isDashboardFile({ nodes: [{ mime: GRAFANA_MIME }, { mime: GRAFANA_MIME }] })).toBe(false)
  })

  it('is empty/garbage safe', () => {
    expect(isDashboardFile()).toBe(false)
    expect(isDashboardFile({ nodes: [] })).toBe(false)
    expect(isDashboardFile({})).toBe(false)
  })
})

describe('getGrafanaMode', () => {
  it('reads the plain metadata-grafana_mode attribute', () => {
    expect(getGrafanaMode({ attributes: { 'metadata-grafana_mode': 'sync' } })).toBe('sync')
  })

  it('translates the wire value "reference" back to "link"', () => {
    expect(getGrafanaMode({ attributes: { 'metadata-grafana_mode': 'reference' } })).toBe('link')
  })

  it('falls back to the bare grafana_mode attribute', () => {
    expect(getGrafanaMode({ attributes: { grafana_mode: 'unmapped' } })).toBe('unmapped')
  })

  it('falls back to the fully-qualified DAV attribute name', () => {
    expect(getGrafanaMode({ attributes: { '{http://nextcloud.org/ns}metadata-grafana_mode': 'ignored' } })).toBe('ignored')
  })

  it('returns empty string when absent (first-load race / untracked file)', () => {
    expect(getGrafanaMode({ attributes: {} })).toBe('')
    expect(getGrafanaMode()).toBe('')
    expect(getGrafanaMode(null)).toBe('')
  })

  it('ignores a non-string mode value', () => {
    expect(getGrafanaMode({ attributes: { 'metadata-grafana_mode': 42 } })).toBe('')
  })
})

describe('canOpenInGrafana', () => {
  it('offers "Open in Grafana" for sync and link (a live dashboard exists)', () => {
    expect(canOpenInGrafana('sync')).toBe(true)
    expect(canOpenInGrafana('link')).toBe(true)
  })

  it('hides "Open in Grafana" for unmapped and ignored (no live dashboard)', () => {
    expect(canOpenInGrafana('unmapped')).toBe(false)
    expect(canOpenInGrafana('ignored')).toBe(false)
  })

  it('stays permissive for an absent/unknown mode (first-load race)', () => {
    expect(canOpenInGrafana('')).toBe(true)
  })
})

describe('canEditAsText', () => {
  it('offers the text editor for every mode that holds the full JSON', () => {
    expect(canEditAsText('sync')).toBe(true)
    expect(canEditAsText('unmapped')).toBe(true)
    expect(canEditAsText('ignored')).toBe(true)
  })

  it('hides the text editor for link (a pointer — nothing to edit)', () => {
    expect(canEditAsText('link')).toBe(false)
  })

  it('stays permissive for an absent/unknown mode (first-load race)', () => {
    expect(canEditAsText('')).toBe(true)
  })
})

describe('defaultOpener', () => {
  it('defaults sync/link to grafana', () => {
    expect(defaultOpener('sync')).toBe('grafana')
    expect(defaultOpener('link')).toBe('grafana')
  })

  it('defaults unmapped/ignored to the text editor', () => {
    expect(defaultOpener('unmapped')).toBe('text')
    expect(defaultOpener('ignored')).toBe('text')
  })

  it('defaults an absent mode to grafana (matches canOpenInGrafana)', () => {
    expect(defaultOpener('')).toBe('grafana')
  })
})
