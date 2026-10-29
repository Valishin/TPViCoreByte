# SAI (UPS) con NUT: apagado limpio del mini PC

Un corte de luz con la base de datos escribiendo puede corromperla. Con un SAI
conectado por USB, **NUT** (Network UPS Tools) apaga el mini PC de forma limpia
cuando la batería se agota: primero para los contenedores del TPV y después
apaga el sistema. Al volver la corriente, la BIOS ("encender al recibir
corriente") lo arranca y el TPV vuelve solo.

> Elige un SAI con puerto **USB HID** (APC Back-UPS, Eaton Ellipse/5E,
> Salicru SPS…): funcionan con el driver `usbhid-ups` sin configurar nada más.

## 1. Instalar y detectar el SAI

```bash
sudo apt install nut
sudo nut-scanner -U          # debe listar el SAI con driver = "usbhid-ups"
```

## 2. Configuración (modo standalone: el SAI y el TPV en el mismo equipo)

`/etc/nut/nut.conf`:

```ini
MODE=standalone
```

`/etc/nut/ups.conf`:

```ini
[sai]
    driver = usbhid-ups
    port = auto
    desc = "SAI del TPV"
    # Apagar cuando quede un 30 % o menos de 3 minutos (lo que llegue antes)
    override.battery.charge.low = 30
    override.battery.runtime.low = 180
```

`/etc/nut/upsd.users` (usuario local de NUT; cambia la contraseña):

```ini
[upsmon]
    password = CAMBIAR-por-una-larga
    upsmon primary
```

`/etc/nut/upsmon.conf`:

```ini
MONITOR sai@localhost 1 upsmon CAMBIAR-por-una-larga primary
MINSUPPLIES 1
SHUTDOWNCMD "/usr/local/sbin/tpv-apagar"
FINALDELAY 5
```

`/usr/local/sbin/tpv-apagar` (`sudo chmod 755`): para el TPV ordenadamente y
apaga.

```bash
#!/bin/sh
cd /opt/tpv && timeout 60 docker compose stop
/sbin/shutdown -h now
```

```bash
sudo chmod 640 /etc/nut/*.conf /etc/nut/upsd.users
sudo systemctl restart nut-server nut-monitor
upsc sai@localhost          # estado: battery.charge, ups.status (OL = red, OB = batería)
```

## 3. Probarlo (fuera del horario del local)

1. `upsc sai@localhost ups.status` → `OL`.
2. Desenchufa el SAI de la pared: pasa a `OB` y en `journalctl -u nut-monitor`
   aparece el aviso de batería.
3. Simulación completa del apagado: `sudo upsmon -c fsd` → para los
   contenedores y apaga.
4. Vuelve a enchufar: el mini PC arranca solo (BIOS) y el TPV y el kiosko
   vuelven en 1-2 minutos.
