/**
 * Starts the API if it is not already up, then gets out of the way.
 *
 * Run by npm as `prestart`, so `npm start` in patient_app brings up the API
 * and Metro together — one command instead of two terminals, and no way to
 * forget the one that makes the phone work.
 *
 * It binds 0.0.0.0 rather than 127.0.0.1: a phone that reaches Metro but not
 * the API looks like a broken app rather than a missing flag.
 */
const { spawn } = require('child_process')
const net = require('net')

const PORT = 8000
// Forward slashes on purpose — Windows accepts them, and they survive being
// copied between shells without turning into escape sequences.
const PHP = 'C:/xampp/php/php.exe'

function isUp() {
  return new Promise((resolve) => {
    const socket = net.connect({ port: PORT, host: '127.0.0.1' })
    socket.setTimeout(1000)
    socket.on('connect', () => { socket.destroy(); resolve(true) })
    socket.on('error', () => resolve(false))
    socket.on('timeout', () => { socket.destroy(); resolve(false) })
  })
}

;(async () => {
  if (await isUp()) {
    console.log('API already running on :' + PORT)
    return
  }

  const child = spawn(PHP, ['-S', '0.0.0.0:' + PORT, '-t', 'public'], {
    cwd: __dirname,
    detached: true,
    stdio: 'ignore',
    windowsHide: true,
  })
  child.unref()

  // Metro starts bundling immediately; the first API call is seconds away, so
  // a short wait here is enough and beats a race the user has to debug.
  for (let i = 0; i < 20; i++) {
    if (await isUp()) {
      console.log('API started on 0.0.0.0:' + PORT)
      return
    }
    await new Promise((r) => setTimeout(r, 250))
  }
  console.log('API did not answer on :' + PORT + ' — check that XAMPP PHP is at ' + PHP)
})()
