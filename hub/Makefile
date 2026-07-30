# Status-Pigeon Hub Makefile
#
# 用法：
#   make            编译当前平台到 dist/
#   make all        交叉编译全部目标架构
#   make linux-amd64 / linux-arm64 / ...   单个目标
#   make clean
#
# modernc.org/sqlite 为纯 Go，无 cgo，可静态交叉编译。
# 注意：其 libc 不支持 mips 架构，故 Hub 不提供 linux-mipsle 目标
# （Agent 无此依赖，可正常交叉编译 mipsle/OpenWrt）。

BINARY   := statuspigeon-hub
DIST     := dist
VERSION  := $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
LDFLAGS  := -s -w

TARGETS := \
	linux-amd64:linux:amd64:: \
	linux-arm64:linux:arm64:: \
	linux-arm:linux:arm:7: \
	linux-386:linux:386:: \
	darwin-amd64:darwin:amd64:: \
	darwin-arm64:darwin:arm64::

.PHONY: all clean current

current:
	@mkdir -p $(DIST)
	CGO_ENABLED=0 go build -trimpath -ldflags '$(LDFLAGS)' -o $(DIST)/$(BINARY) .

all: $(foreach t,$(TARGETS),build-$(word 1,$(subst :, ,$t)))

define BUILD_RULE
build-$(word 1,$(subst :, ,$(1))):
	@mkdir -p $(DIST)
	CGO_ENABLED=0 GOOS=$(word 2,$(subst :, ,$(1))) GOARCH=$(word 3,$(subst :, ,$(1))) \
	$(if $(word 4,$(subst :, ,$(1))),GOARM=$(word 4,$(subst :, ,$(1))),) \
	$(if $(word 5,$(subst :, ,$(1))),GOMIPS=$(word 5,$(subst :, ,$(1))),) \
	go build -trimpath -ldflags '$(LDFLAGS)' -o $(DIST)/$(BINARY)-$(word 1,$(subst :, ,$(1))) .

$(word 1,$(subst :, ,$(1))): build-$(word 1,$(subst :, ,$(1)))
endef

$(foreach t,$(TARGETS),$(eval $(call BUILD_RULE,$(t))))

clean:
	rm -rf $(DIST)

.DEFAULT_GOAL := current
