include $(TOPDIR)/rules.mk

PKG_NAME:=luci-app-statuspigeon
PKG_VERSION:=1.0.9
PKG_RELEASE:=1
PKG_LICENSE:=MIT
PKG_MAINTAINER:=Status Pigeon contributors

include $(INCLUDE_DIR)/package.mk

define Package/luci-app-statuspigeon
  SECTION:=luci
  CATEGORY:=LuCI
  SUBMENU:=3. Applications
  TITLE:=Status Pigeon JSON reporting agent
  PKGARCH:=all
  DEPENDS:=+luci-base +rpcd-mod-file +curl +ca-bundle +jshn +jsonfilter
endef

define Package/luci-app-statuspigeon/description
  LuCI configuration page and shell-based JSON push agent for Status Pigeon.
  It supports periodic reports and network-up event reports, with no listen mode.
endef

define Package/luci-app-statuspigeon/conffiles
/etc/config/statuspigeon
endef

define Package/luci-app-statuspigeon/install
	$(INSTALL_DIR) $(1)/etc/config
	$(INSTALL_CONF) ./root/etc/config/statuspigeon $(1)/etc/config/statuspigeon

	$(INSTALL_DIR) $(1)/etc/init.d
	$(INSTALL_BIN) ./root/etc/init.d/statuspigeon $(1)/etc/init.d/statuspigeon

	$(INSTALL_DIR) $(1)/etc/hotplug.d/iface
	$(INSTALL_BIN) ./root/etc/hotplug.d/iface/95-statuspigeon $(1)/etc/hotplug.d/iface/95-statuspigeon

	$(INSTALL_DIR) $(1)/usr/bin
	$(INSTALL_BIN) ./root/usr/bin/statuspigeon-report $(1)/usr/bin/statuspigeon-report
	$(INSTALL_BIN) ./root/usr/bin/statuspigeon-scheduler $(1)/usr/bin/statuspigeon-scheduler
	$(INSTALL_BIN) ./root/usr/bin/statuspigeon-status $(1)/usr/bin/statuspigeon-status

	$(INSTALL_DIR) $(1)/usr/share/luci/menu.d
	$(INSTALL_DATA) ./root/usr/share/luci/menu.d/luci-app-statuspigeon.json $(1)/usr/share/luci/menu.d/luci-app-statuspigeon.json

	$(INSTALL_DIR) $(1)/usr/share/rpcd/acl.d
	$(INSTALL_DATA) ./root/usr/share/rpcd/acl.d/luci-app-statuspigeon.json $(1)/usr/share/rpcd/acl.d/luci-app-statuspigeon.json

	$(INSTALL_DIR) $(1)/www/luci-static/resources/view/statuspigeon
	$(INSTALL_DATA) ./root/www/luci-static/resources/view/statuspigeon/config.js $(1)/www/luci-static/resources/view/statuspigeon/config.js
endef

# This is a files-only package; there is no source tree to compile.
define Build/Compile
endef

$(eval $(call BuildPackage,luci-app-statuspigeon))
