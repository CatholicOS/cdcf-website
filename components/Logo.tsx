import Image from 'next/image'
import clsx from 'clsx'

interface LogoProps {
  className?: string
  width?: number
  height?: number
}

export default function Logo({ className, width = 48, height = 48 }: LogoProps) {
  return (
    <Image
      src="/logo.svg"
      alt="CDCF — Catholic Digital Commons Foundation"
      width={width}
      height={height}
      className={clsx('inline-block', className)}
      priority
    />
  )
}
