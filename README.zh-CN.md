# 房淘 Markdown 导入导出

[English](README.md) | 简体中文

房淘 Markdown 导入导出是一款用于在 Markdown 文件与 WordPress 之间迁移内容的插件。它可以导入单个 Markdown 文档，也可以导入包含多个 Markdown 文件和本地图片的 ZIP 压缩包；同时支持将单篇或多篇 WordPress 内容导出为便于迁移和备份的 Markdown ZIP。

## 功能特性

- 导入 `.md` 和 `.markdown` 文件。
- 导入包含多份 Markdown 文档的 ZIP 压缩包。
- 将 Markdown 相对路径引用的本地图片导入 WordPress 媒体库。
- 自动将正文中的本地图片引用替换为 WordPress 附件地址。
- 未指定特色图片时，可将首张导入的本地图片设为特色图片。
- 导入时选择目标文章类型和文章状态。
- 从内容列表的行操作中导出单篇内容。
- 通过 WordPress 批量操作导出多篇内容。
- 将 WordPress HTML 和区块内容转换为 GitHub Flavored Markdown。
- 将本地媒体库图片写入 `images/` 目录，并在 Markdown 中使用相对路径。
- 在导出的 Markdown 中包含已支持的 Front Matter 元数据。
- OSS 配置完整时保持原有云端流程；检测到 OSS 配置不完整时回退到本地媒体库，避免上传时出现严重错误。

## 运行要求

- WordPress 6.0 或更高版本
- PHP 7.4 或更高版本
- ZIP 导入和全部导出功能需要 PHP ZIP 扩展
- HTML 转 Markdown 导出需要 PHP DOM 扩展
- WordPress 上传目录必须可写

上传文件的最大体积由 PHP 和 Web 服务器配置统一控制。

## 安装方法

### 通过 WordPress 后台安装

1. 下载或打包包含 `fangtao-markdown-zip-importer` 目录的插件 ZIP。
2. 打开 **插件 > 安装插件 > 上传插件**。
3. 选择 ZIP 并完成安装。
4. 启用 **房淘 Markdown 导入导出**。
5. 在 WordPress 后台打开新增的 **Markdown** 菜单。

### 手动安装

1. 将 `fangtao-markdown-zip-importer` 目录上传到 `wp-content/plugins/`。
2. 在 **插件 > 已安装插件** 中启用插件。

## 导入 Markdown

打开 **Markdown > Markdown 导入**。

1. 选择 `.md`、`.markdown` 或 `.zip` 文件。
2. 选择导入到的内容类型。
3. 选择 **草稿** 或 **立即发布**。
4. 点击 **上传并导入**。

每个 Markdown 文件会创建一篇新的 WordPress 内容。导入页面支持文章、页面，以及当前用户有权编辑的公开自定义文章类型。

### 导入单个 Markdown

不依赖本地资源的 Markdown 文件可以直接上传。远程图片地址会保持原样，不会自动下载。

### 导入 ZIP

将 Markdown 和本地图片按相对目录一起打包：

```text
articles/
  living-room.md
  images/
    living-room.jpg
```

在 Markdown 中使用相对于文档的图片路径：

```markdown
![客厅](images/living-room.jpg)
```

导入时，支持的本地图片会进入 WordPress 媒体库，生成内容中的图片引用也会同步更新。

## Front Matter

导入器支持安全的单行 YAML 风格 Front Matter 子集：

```yaml
---
title: 安静舒适的客厅
slug: calm-living-room
excerpt: 一份打造安静舒适空间的实用指南。
featured_image: images/cover.jpg
---
```

| 字段 | 导入 | 导出 | 说明 |
| --- | --- | --- | --- |
| `title` | 支持 | 支持 | 未填写时使用首个一级标题或文件名。 |
| `slug` | 支持 | 支持 | 设置 WordPress 文章别名。 |
| `excerpt` | 支持 | 支持 | 导入时也兼容 `description`。 |
| `featured_image` | 支持 | 支持 | 导入时也兼容 `cover` 和 `image`。 |
| `date` | 暂不支持 | 支持 | 导出时用于增强可迁移性。 |
| `post_type` | 在界面选择 | 支持 | 导入目标由导入表单控制。 |
| `status` | 在界面选择 | 支持 | 导入状态由导入表单控制。 |

暂不支持复杂 YAML 值，也不会从 Front Matter 导入分类和标签。

## 导出 Markdown

打开 **Markdown > Markdown 导出**，可以查看支持导出的内容类型。

### 单篇导出

1. 打开任意 WordPress 内容列表。
2. 将鼠标悬停在需要导出的内容上。
3. 点击 **导出 Markdown**。

下载的 ZIP 结构如下：

```text
article.md
images/
  article-image.jpg
```

### 批量导出

1. 打开 WordPress 内容列表。
2. 勾选多篇内容。
3. 在 **批量操作** 中选择 **导出 Markdown ZIP**。
4. 点击应用。

每篇内容会使用独立目录：

```text
article-one-123/
  article.md
  images/
article-two-456/
  article.md
  images/
```

属于当前 WordPress 上传目录的图片会复制到对应的 `images/` 目录。外部图片继续保留外部 URL。

## 支持的内容结构

转换器支持常见 WordPress 内容，包括：

- 标题和段落
- 粗体、斜体和删除线
- 链接和图片
- 有序列表和无序列表
- 引用
- 行内代码和代码块
- GitHub Flavored Markdown 表格
- 图片说明
- 基础音频、视频和嵌入媒体链接

导出前会处理短代码。复杂区块或第三方短代码生成的 HTML 在转换为 Markdown 时可能会被简化。

## 导入限制与安全

导入器包含以下安全限制：

- 每个 ZIP 最多 500 个条目
- 解压后文件总量最大 200 MB
- 单个 Markdown 文件最大 2 MB
- 单张图片最大 20 MB
- 支持 JPG、JPEG、PNG、GIF、WebP 和 AVIF
- 拒绝 ZIP 目录穿越和符号链接
- 忽略不支持的压缩包文件
- 导入后的 HTML 会经过 WordPress 清理
- 导入导出操作均检查 WordPress 权限和 nonce

## 当前限制

- 暂不提供 Markdown 编辑器和实时预览。
- 暂未在 Gutenberg 或经典编辑器侧边栏提供工具。
- 不会自动下载远程图片。
- 暂不从 Front Matter 导入分类、标签和发布日期。
- Markdown 解析器目前固定为 GitHub Flavored Markdown。
- 暂不包含内部文档管理系统和 REST API。
- 复杂页面构建器生成的 HTML 无法保证无损转换为 Markdown。

## 常见问题

### 为什么无法导入 ZIP？

请启用 PHP ZIP 扩展。没有 ZIP 扩展时，仍可导入单个 `.md` 或 `.markdown` 文件。

### 为什么无法使用导出功能？

导出功能同时依赖 PHP ZIP 和 DOM 扩展。

### 为什么导入图片保存在本地而不是 OSS？

如果检测到 OSS 插件已启用，但 Bucket、AccessKey 或角色配置不完整，导入器会使用 WordPress 默认本地媒体库，避免出现致命上传错误。完整配置 OSS 后即可恢复其正常处理流程。

### 为什么图片仍然是外部地址？

插件只会导入上传 ZIP 中包含的本地图片。远程图片地址会被有意保留。

### 导入时会覆盖已有文章吗？

不会。每个 Markdown 文档都会创建一篇新内容。

## 更新记录

### 1.1.1

- 修复 OSS 配置不完整时导入图片导致严重错误的问题。
- 为媒体导入异常增加可读错误处理。

### 1.1.0

- 增加单篇和批量 Markdown ZIP 导出。
- 增加 HTML 转 Markdown 和相对图片打包。
- 增加导出的 Front Matter 数据。

### 1.0.0

- 首次发布 Markdown 和 ZIP 导入。
- 支持本地图片导入和特色图片设置。

## 开源协议

GPL-2.0-or-later，详见 [GNU General Public License v2.0](https://www.gnu.org/licenses/gpl-2.0.html)。

## 作者

Fangtao
