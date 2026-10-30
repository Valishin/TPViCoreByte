# DECLARACIÓN RESPONSABLE DEL SISTEMA INFORMÁTICO DE FACTURACIÓN

> **Borrador pendiente de revisión por el asesor.** Contenido y orden según el artículo 15
> de la Orden HAC/1177/2024 y el ejemplo 1 («solo VERI*FACTU») publicado por la AEAT.

**1.a) Nombre del sistema informático a que se refiere esta declaración responsable:**
iCOREBYTE TPV

**1.b) Código identificador del sistema informático a que se refiere el apartado a) de esta declaración responsable:**
IC

**1.c) Identificador completo de la versión concreta del sistema informático a que se refiere esta declaración responsable:**
{{version}}

**1.d) Componentes, hardware y software, de que consta el sistema informático a que se refiere esta declaración responsable, junto con una breve descripción de lo que hace dicho sistema informático y de sus principales funcionalidades:**
Se trata de un software de terminal punto de venta (TPV) para hostelería que se instala en un
equipo del propio local (mini PC con sistema operativo Debian y contenedores Docker), con
pantalla táctil, impresoras de tickets y, opcionalmente, teléfonos móviles de la misma red local
como terminales de toma de pedidos. Todos los terminales trabajan contra un único servidor
local, que es el que expide las facturas y genera, conserva y remite los registros de facturación.

Funcionalidades principales: toma de pedidos de sala, barra, para llevar y a domicilio; cobro;
expedición de facturas simplificadas (tickets), facturas completas, facturas en sustitución de
simplificadas y facturas rectificativas, con su código QR tributario; anulación de facturas
emitidas por error; cierres de caja; exportación fiscal; y gestión del local desde un panel de
administración web.

Componentes que intervienen en el cumplimiento del Reglamento (todos del mismo productor y
con ciclo de versiones vinculado, incluidos en esta declaración): generación de los registros de
facturación de alta y de anulación con su huella encadenada en la misma transacción que la
factura; impresión de la factura con el código QR; y servicio de remisión automática de los
registros a la sede electrónica de la AEAT, con control de flujo y reintentos.

**1.e) Indicación de si el sistema informático a que se refiere esta declaración responsable se ha producido de tal manera que, a los efectos de cumplir con el Reglamento, solo pueda funcionar exclusivamente como «VERI*FACTU»:**
S - Sí

**1.f) Indicación de si el sistema informático a que se refiere esta declaración responsable permite ser usado por varios obligados tributarios o por un mismo usuario para dar soporte a la facturación de varios obligados tributarios:**
N - No

**1.g) Tipos de firma utilizados para firmar los registros de facturación y de evento en el caso de que el sistema informático a que se refiere esta declaración responsable no sea utilizado como «VERI*FACTU»:**
Dado que se trata de un sistema que solo puede funcionar en la modalidad «VERI*FACTU», no se
realiza una firma electrónica expresa de los registros de facturación (artículo 16.3 del
Reglamento).

**1.h) Nombre y apellidos de la persona productora del sistema informático a que se refiere esta declaración responsable:**
Alex Valishin Abubekirov

**1.i) Número de identificación fiscal (NIF) español de la persona productora del sistema informático a que se refiere esta declaración responsable:**
49298273G

**1.j) Dirección postal completa de contacto de la persona productora del sistema informático a que se refiere esta declaración responsable:**
Carretera de Palamós 57, bajos. 17220 – Sant Feliu de Guíxols (Girona). España.
*(Confirmar el código postal.)*

**1.k)** La persona productora del sistema informático a que se refiere esta declaración
responsable hace constar que dicho sistema informático, en la versión indicada en ella, cumple
con lo dispuesto en el artículo 29.2.j) de la Ley 58/2003, de 17 de diciembre, General
Tributaria, en el Reglamento que establece los requisitos que deben adoptar los sistemas y
programas informáticos o electrónicos que soporten los procesos de facturación de empresarios y
profesionales, y la estandarización de formatos de los registros de facturación, aprobado por el
Real Decreto 1007/2023, de 5 de diciembre, en la Orden HAC/1177/2024, de 17 de octubre, y en la
sede electrónica de la Agencia Estatal de Administración Tributaria para todo aquello que
complete las especificaciones de dicha orden.

**1.l) Fecha y lugar en que la persona productora del sistema informático suscribe esta declaración responsable:**
[día] de [mes] de [año], en Sant Feliu de Guíxols (España).

---

## Anexo (información adicional recomendada, art. 15.2 de la Orden)

**Cómo cumple el sistema las especificaciones técnicas** (resumen; detalle en `docs/verifactu/PLAN.md`):

- Registro de facturación de alta o anulación generado en la misma transacción que la factura,
  con huella SHA-256 encadenada al registro anterior (arts. 7, 10, 11 y 13 de la Orden) y una sola
  cadena por instalación.
- Remisión automática e inmediata a la AEAT con certificado electrónico cualificado, control de
  flujo y reintentos; aviso visible mientras quede algún registro sin remitir (art. 16).
- Código QR de 30 a 40 mm, nivel de corrección M, al principio de la factura, con el texto
  «QR tributario:» y la frase «VERI*FACTU» o «Factura verificable en la sede electrónica de la
  AEAT» (arts. 20 y 21).
- Numeración correlativa por serie y año; los documentos y los registros no se pueden modificar
  ni borrar (bloqueo en la base de datos).
- Comprobación de la cadena de registros bajo demanda y automática.
