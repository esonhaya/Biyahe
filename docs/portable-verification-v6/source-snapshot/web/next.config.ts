import type { NextConfig } from "next";

const apiInternalUrl = process.env.BIYAHE_API_INTERNAL_URL?.replace(/\/$/, "");

const nextConfig: NextConfig = {
  output: "standalone",
  ...(apiInternalUrl
    ? {
        async rewrites() {
          return [{ source: "/api/v1/:path*", destination: `${apiInternalUrl}/:path*` }];
        },
      }
    : {}),
};

export default nextConfig;
