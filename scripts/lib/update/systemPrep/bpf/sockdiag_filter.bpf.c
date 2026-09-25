// SPDX-License-Identifier: GPL-2.0
/*
 * PMSS opt-in socket-table privacy: BPF-LSM filter for NETLINK_SOCK_DIAG.
 *
 * Part of the opt-in per-host socket-table privacy feature (docs/adr/0067). Loaded
 * only when /etc/seedbox/config/socket-table-privacy.enabled is present and the host
 * carries a BPF-capable LSM stack + kernel BTF; the loader fails open otherwise.
 *
 * Accounts with uid >= 1000 (customer accounts, their subordinate uid ranges via
 * /etc/subuid, and nobody) may still list listening and unconnected sockets (what
 * `ss -l` / `ss -tlnp` ask for — a documented customer workflow for finding a free
 * port), but may not dump connected-socket rows (which carry a peer's remote address)
 * or query a single socket by id. Root and system accounts (uid < 1000) are
 * unaffected. Other diag families (unix, netlink, packet) pass through unchanged.
 *
 * Built once with CO-RE (BPF_CORE_READ) so one object relocates against each host's
 * own BTF. Rebuild instructions: scripts/lib/update/systemPrep/bpf/README.md.
 */
#include "vmlinux.h"
#include <bpf/bpf_helpers.h>
#include <bpf/bpf_core_read.h>
#include <bpf/bpf_tracing.h>

#define EACCES 13
#define NETLINK_SOCK_DIAG 4
#define AF_INET 2
#define AF_INET6 10
#define TCPDIAG_GETSOCK 18
#define DCCPDIAG_GETSOCK 19
#define SOCK_DIAG_BY_FAMILY 20
#define NLM_F_DUMP 0x300
#define STATE_CLOSE 7
#define STATE_LISTEN 10
#define ALLOWED_STATES ((1U << STATE_CLOSE) | (1U << STATE_LISTEN))
#define FIRST_FILTERED_UID 1000

char LICENSE[] SEC("license") = "GPL";

struct {
	__uint(type, BPF_MAP_TYPE_HASH);
	__uint(max_entries, 8192);
	__type(key, u32);
	__type(value, u64);
} deny_count SEC(".maps");

struct diag_head {
	struct nlmsghdr nlh;
	u8 family;
	u8 protocol;
	u8 ext;
	u8 pad;
	u32 states;
};

static __always_inline int deny(u32 uid)
{
	u64 one = 1;
	u64 *count = bpf_map_lookup_elem(&deny_count, &uid);

	if (count)
		__sync_fetch_and_add(count, 1);
	else
		bpf_map_update_elem(&deny_count, &uid, &one, BPF_NOEXIST);
	return -EACCES;
}

SEC("lsm/netlink_send")
int BPF_PROG(pmss_sockdiag_filter, struct sock *sk, struct sk_buff *skb, int ret)
{
	struct diag_head head = {};
	unsigned char *data;
	u32 uid, len;

	if (ret)
		return ret;
	uid = (u32)bpf_get_current_uid_gid();
	if (uid < FIRST_FILTERED_UID)
		return 0;
	if (BPF_CORE_READ(sk, sk_protocol) != NETLINK_SOCK_DIAG)
		return 0;

	len = BPF_CORE_READ(skb, len);
	data = BPF_CORE_READ(skb, data);
	if (len < sizeof(struct nlmsghdr))
		return 0; /* the kernel rejects a truncated header itself */
	if (bpf_probe_read_kernel(&head.nlh, sizeof(head.nlh), data))
		return deny(uid);

	/* one message per send: a second message would escape the checks below */
	if (((head.nlh.nlmsg_len + 3) & ~3U) < len)
		return deny(uid);
	if (head.nlh.nlmsg_type == TCPDIAG_GETSOCK || head.nlh.nlmsg_type == DCCPDIAG_GETSOCK)
		return deny(uid);
	if (head.nlh.nlmsg_type != SOCK_DIAG_BY_FAMILY || len < sizeof(head))
		return 0;
	if (bpf_probe_read_kernel(&head, sizeof(head), data))
		return deny(uid);
	if (head.family != AF_INET && head.family != AF_INET6)
		return 0;
	if ((head.nlh.nlmsg_flags & NLM_F_DUMP) != NLM_F_DUMP)
		return deny(uid);
	if (head.states & ~ALLOWED_STATES)
		return deny(uid);
	return 0;
}
