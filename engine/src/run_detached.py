#!/usr/bin/env python3
"""Start een engine-commando volledig losgekoppeld (eigen sessie via os.setsid + dubbele fork) zodat het
de harness-/shell-timeout overleeft. Output gaat naar het opgegeven logbestand. Print de PID en keert
direct terug. Gebruik: run_detached.py <logfile> <script.py> [args...] — env (o.a. OPTIMIZE_COINS) erft mee.
"""
import os
import sys

logfile = sys.argv[1]
cmd = [sys.executable] + sys.argv[2:]

# dubbele fork -> volledig losgekoppeld van de aanroepende shell/sessie
pid = os.fork()
if pid > 0:
    # ouder: wacht kort tot het kind de PID heeft geschreven, print en stop
    os.waitpid(pid, 0)
    try:
        print(open(logfile + ".pid").read().strip())
    except OSError:
        print("(pid onbekend)")
    sys.exit(0)

os.setsid()                      # nieuwe sessie -> geen controlling terminal, eigen proces-groep
pid2 = os.fork()
if pid2 > 0:
    os._exit(0)                  # eerste kind stopt; kleinkind is nu volledig wees

# kleinkind: redirect IO naar het logbestand en exec het echte commando
with open(logfile, "ab", buffering=0) as log:
    os.dup2(log.fileno(), 1)
    os.dup2(log.fileno(), 2)
devnull = os.open(os.devnull, os.O_RDONLY)
os.dup2(devnull, 0)
open(logfile + ".pid", "w").write(str(os.getpid()))
os.execvp(cmd[0], cmd)
