FROM debian:13
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update -qq && apt-get install -y -qq systemd systemd-sysv util-linux curl ca-certificates procps iproute2 >/dev/null \
 && curl -fsSL https://tailscale.com/install.sh | sh >/dev/null 2>&1 \
 && useradd -u 1000 -M -s /usr/sbin/nologin tpvapp \
 && rm -f /etc/systemd/system/multi-user.target.wants/getty* \
 && systemctl mask getty@tty1.service console-getty.service
STOPSIGNAL SIGRTMIN+3
CMD ["/lib/systemd/systemd"]
