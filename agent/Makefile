# Status-Pigeon Agent 交叉编译 Makefile
#
# 用法：
#   make            编译当前平台到 dist/
#   make all        交叉编译全部目标架构
#   make linux-amd64 / linux-mipsle / ...   单个目标
#   make clean
#
# 全部目标 CGO_ENABLED=0，纯静态，无外部 .so 依赖，适合 OpenWrt 等精简系统。

BINARY   := statuspigeon-agent
DIST     := dist
VERSION  := $(shell git describe --tags --always --dirty 2>/dev/null || echo dev)
LDFLAGS  := -s -w -X 'main.AgentVersion=$(VERSION)'

# 目标列表：target GOOS/GOARCH/附加参数
TARGETS := \
	linux-amd64:linux:amd64:: \
	linux-arm64:linux:arm64:: \
	linux-arm:linux:arm:7: \
	linux-mipsle:linux:mipsle::softfloat \
	linux-386:linux:386:: \
	darwin-amd64:darwin:amd64:: \
	darwin-arm64:darwin:arm64::

.PHONY: all clean $(CURRENT)

# 当前平台
CURRENT := current
.PHONY: $(CURRENT)
$(CURRENT):
	@mkdir -p $(DIST)
	CGO_ENABLED=0 go build -trimpath -ldflags '$(LDFLAGS)' -o $(DIST)/$(BINARY) .

all: $(foreach t,$(TARGETS),build-$(word 1,$(subst :, ,$t)))

# 通用构建规则：参数 = name:GOOS:GOARCH:GOARM:GOMIPS
define BUILD_RULE
build-$(word 1,$(subst :, ,$(1))):
	@mkdir -p $(DIST)
	CGO_ENABLED=0 GOOS=$(word 2,$(subst :, ,$(1))) GOARCH=$(word 3,$(subst :, ,$(1))) \
	$(if $(word 4,$(subst :, ,$(1))),GOARM=$(word 4,$(subst :, ,$(1))),) \
	$(if $(word 5,$(subst :, ,$(1))),GOMIPS=$(word 5,$(subst :, ,$(1))),) \
	go build -trimpath -ldflags '$(LDFLAGS)' -o $(DIST)/$(BINARY)-$(word 1,$(subst :, ,$(1))) .

# 暴露短名目标
$(word 1,$(subst :, ,$(1))): build-$(word 1,$(subst :, ,$(1)))
endef

$(foreach t,$(TARGETS),$(eval $(call BUILD_RULE,$(t))))

clean:
	rm -rf $(DIST)

# 默认：当前平台
.DEFAULT_GOAL := current
